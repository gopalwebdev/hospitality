<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatedNames;
use Carbon\CarbonImmutable;
use Database\Factories\MenuCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A section of one of a tenant's menus, at either of its two levels.
 *
 * A top-level category is Starters, Biryani, Desserts. A category with a
 * `parent_id` is a subdivision of one — Biryani → Chicken, Mutton, Vegetable —
 * and both live in this one table.
 *
 * One table rather than two because a dish then points at exactly one category
 * whichever level it sits on, so there is no (category, sub-category) pair to
 * keep consistent and no composite key needed to police one. Moving a
 * subdivision under a different category is a single `parent_id` write, and its
 * dishes follow because they were never pointing at the parent.
 *
 * Two levels and no more. `isTopLevel()` and `isSubCategory()` are the whole of
 * that distinction, and booted() refuses a parent that is itself nested.
 *
 * Categories are ordered by hand rather than alphabetically, because a menu is
 * read in the order the tenant means it to be read. `position` orders a row
 * among its **siblings** — top-level categories against each other, and the
 * subdivisions of one category against each other.
 *
 * menu_id is carried alongside tenant_id and the pair is a composite foreign
 * key into menus, so a section can never end up under another tenant's
 * menu; (parent_id, menu_id) is a second composite key into this table, so a
 * subdivision can never end up under a category on a different menu. The name
 * is translated — see HasTranslatedNames.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_id
 * @property int|null $parent_id
 * @property-read Menu $menu
 * @property-read MenuCategory|null $parent
 * @property string $name
 * @property int $position
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['menu_id', 'parent_id', 'name', 'position', 'is_active'])]
class MenuCategory extends Model
{
    /** @use HasFactory<MenuCategoryFactory> */
    use HasFactory;

    use HasTranslatedNames;

    /**
     * @var list<string>
     */
    public array $translatable = ['name'];

    /**
     * Categories are new and hidden until told otherwise is the wrong default,
     * so an unsaved one already reads as active under Model::shouldBeStrict().
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position' => 0,
        'is_active' => true,
    ];

    /**
     * Take the tenant from the menu this sits on, and refuse a third level.
     *
     * The tenant is the same one by definition — the composite foreign key
     * insists on it — so nothing that creates a category has to remember.
     * Filament's tenancy stamps the model a *resource* is saving, and categories
     * are not edited through one: they are created by the relation managers on
     * the menu's page, which write through a relation that sets only menu_id.
     *
     * The depth guard is here because no foreign key can express it: a parent
     * must be a top-level category. Without it a menu could nest indefinitely,
     * which is not a shape the panel or the guest app can draw.
     */
    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            if (blank($category->tenant_id) && filled($category->menu_id)) {
                $category->tenant_id = Menu::query()
                    ->withoutGlobalScopes()
                    ->whereKey($category->menu_id)
                    ->value('tenant_id');
            }

            // Only a parent that is being set needs checking. Rearranging a menu
            // saves every row it renumbers, and asking again about a parent
            // nobody changed would be one query per sibling.
            if (blank($category->parent_id) || ! $category->isDirty('parent_id')) {
                return;
            }

            throw_if(
                $category->parent_id === $category->getKey(),
                LogicException::class,
                'A category cannot be its own parent.',
            );

            $parentIsNested = self::query()
                ->withoutGlobalScopes()
                ->whereKey($category->parent_id)
                ->whereNotNull('parent_id')
                ->exists();

            throw_if(
                $parentIsNested,
                LogicException::class,
                'A menu is two levels deep: a sub-category cannot hold another.',
            );
        });
    }

    /**
     * The tenant whose menu this belongs to.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The menu this section appears on.
     *
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * The category this subdivides, or null when it is top level.
     *
     * @return BelongsTo<MenuCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The subdivisions of this category, when it has any.
     *
     * Most categories have none. A tenant subdivides "Biryani" into
     * Chicken, Mutton and Vegetable only when the category is long enough to be
     * worth breaking up.
     *
     * @return HasMany<MenuCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The items filed under this category itself.
     *
     * A dish belongs to exactly one category, so this is every dish that names
     * this row — not the ones in its subdivisions, which name those instead.
     *
     * @return HasMany<MenuItem, $this>
     */
    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    /**
     * Whether this is a section of the menu rather than a subdivision of one.
     */
    public function isTopLevel(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Whether this subdivides another category.
     */
    public function isSubCategory(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * How this reads as a branch of the menu: "Biryani", or "Biryani › Chicken".
     *
     * Reads a loaded parent only; a caller that has not loaded it gets the
     * category's own name rather than a lazy load, which Model::shouldBeStrict()
     * would throw on anyway.
     */
    public function path(string $separator = ' › '): string
    {
        $parent = $this->relationLoaded('parent') ? $this->getRelation('parent') : null;

        return $parent instanceof self
            ? $parent->name.$separator.$this->name
            : $this->name;
    }

    /**
     * Limit the query to the sections of a menu, leaving subdivisions out.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Limit the query to subdivisions, leaving the sections out.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSubCategories(Builder $query): void
    {
        $query->whereNotNull('parent_id');
    }

    /**
     * Limit the query to categories a guest should see.
     *
     * A subdivision is only showing if its parent is too — hiding a section has
     * to take everything under it off the menu.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $withParent): Builder => $withParent
                ->whereNull('parent_id')
                ->orWhereRelation('parent', 'is_active', true));
    }

    /**
     * Order the way the tenant arranged its menu, name only to break ties.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInMenuOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy(self::fallbackLocalePath());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
