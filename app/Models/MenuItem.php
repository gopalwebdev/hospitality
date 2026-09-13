<?php

namespace App\Models;

use App\Enums\FoodType;
use App\Enums\ItemAvailability;
use App\Models\Concerns\HasTranslatedNames;
use App\Models\Concerns\IsPricedOnAMenu;
use Carbon\CarbonImmutable;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dish on one tenant's menu.
 *
 * The price is an integer count of the currency's minor unit, never a float:
 * ₹249.50 is stored as 24950. App\Enums\Currency converts at the edges, and
 * the currency itself comes from the tenant's settings, so nothing here
 * assumes rupees. `compare_at_price_minor_units` is the higher price shown
 * struck through beside it and is null on almost every dish — see
 * IsPricedOnAMenu.
 *
 * A dish is filed under exactly one category, which may be a section of the
 * menu or one of that section's subdivisions — MenuCategory holds both in one
 * table. That is the whole of it: there is no second column and so no pair to
 * keep consistent.
 *
 * tenant_id is carried directly as well as through the category. That is
 * deliberate — it is the tenant boundary, and a composite foreign key on
 * (menu_category_id, tenant_id) makes it impossible for the two to
 * disagree.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_category_id
 * @property-read MenuCategory $menuCategory
 * @property string $name
 * @property string|null $description
 * @property int $price_minor_units
 * @property int|null $compare_at_price_minor_units
 * @property int|null $tax_rate_basis_points
 * @property string|null $hsn_code
 * @property FoodType $food_type
 * @property ItemAvailability $availability
 * @property bool $is_featured
 * @property int $featured_position
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'menu_category_id',
    'name',
    'description',
    'price_minor_units',
    'compare_at_price_minor_units',
    'tax_rate_basis_points',
    'hsn_code',
    'food_type',
    'availability',
    'is_featured',
    'featured_position',
    'position',
])]
class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory;

    use HasTranslatedNames;
    use IsPricedOnAMenu;

    /**
     * @var list<string>
     */
    public array $translatable = ['name', 'description'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position' => 0,
        'availability' => ItemAvailability::Available->value,
        'is_featured' => false,
        'featured_position' => 0,
    ];

    /**
     * A dish that leaves a menu stops being featured on it.
     *
     * The featured row is "what *this* menu leads with", so a dish carried to a
     * category on another menu cannot still be at the top of the one it left —
     * and must not appear at the top of the one it arrived on without anyone
     * choosing it there.
     *
     * This lives on the model rather than in the form that re-files a dish,
     * because it has to hold however the dish is written. It used to be in a
     * MoveItemToCategory action; the action is gone and the rule is not.
     *
     * A dish moved *within* its menu keeps its place in the row. Moving a whole
     * category between menus does not change any dish's menu_category_id, so
     * this cannot see it — MoveCategoryToMenu unfeatures that branch itself.
     */
    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            if (! $item->is_featured || ! $item->isDirty('menu_category_id')) {
                return;
            }

            $menus = MenuCategory::query()
                ->withoutGlobalScopes()
                ->whereKey([$item->getOriginal('menu_category_id'), $item->menu_category_id])
                ->pluck('menu_id', 'id');

            $left = $menus[$item->getOriginal('menu_category_id')] ?? null;
            $arrived = $menus[$item->menu_category_id] ?? null;

            if ($left !== $arrived) {
                $item->is_featured = false;
                $item->featured_position = 0;
            }
        });
    }

    /**
     * The tenant selling this.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The section of the menu this sits under.
     *
     * @return BelongsTo<MenuCategory, $this>
     */
    public function menuCategory(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class);
    }

    /**
     * The extras this dish may be ordered with.
     *
     * @return HasMany<MenuItemAddition, $this>
     */
    public function additions(): HasMany
    {
        return $this->hasMany(MenuItemAddition::class);
    }

    /**
     * The lines of every combo this dish appears in.
     *
     * @return HasMany<MenuComboItem, $this>
     */
    public function comboItems(): HasMany
    {
        return $this->hasMany(MenuComboItem::class);
    }

    /**
     * The combos this dish is part of.
     *
     * @return BelongsToMany<MenuCombo, $this>
     */
    public function combos(): BelongsToMany
    {
        return $this->belongsToMany(MenuCombo::class, 'menu_combo_items')
            ->withPivot(['quantity', 'position'])
            ->withTimestamps();
    }

    /**
     * Whether a guest may order this right now.
     */
    public function isOrderable(): bool
    {
        return $this->availability->isOrderable();
    }

    /**
     * Limit the query to what a guest may actually order right now.
     *
     * Every level matters: hiding a whole menu has to take its sections, their
     * subdivisions and all the dishes with it. The category clause covers both
     * levels at once, because MenuCategory::scopeActive() already requires a
     * subdivision's parent to be showing too.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOrderable(Builder $query): void
    {
        $query->whereIn('availability', ItemAvailability::orderableValues())
            ->whereRelation('menuCategory', 'is_active', true)
            ->whereRelation('menuCategory.menu', 'is_active', true)
            ->where(fn (Builder $underAShowingSection): Builder => $underAShowingSection
                ->whereRelation('menuCategory', 'parent_id')
                ->orWhereRelation('menuCategory.parent', 'is_active', true));
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
     * Limit the query to the dishes on one menu.
     *
     * A dish reaches its menu through its category, so this states that hop
     * once rather than at each call site.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOnMenu(Builder $query, int $menuId): void
    {
        $query->whereRelation('menuCategory', 'menu_id', $menuId);
    }

    /**
     * Limit the query to the dishes one menu leads with.
     *
     * Featuring is a flag on the dish, and a dish reaches its menu through its
     * section — so this states that hop once rather than at each call site.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeFeaturedOnMenu(Builder $query, int $menuId): void
    {
        $query->where('is_featured', true)->onMenu($menuId);
    }

    /**
     * Order the way the tenant arranged the dishes it leads with.
     *
     * A separate order from scopeInMenuOrder(): that one places a dish inside
     * its section, this one places it in the featured row, and a dish answers
     * both questions at once.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInFeaturedOrder(Builder $query): void
    {
        $query->orderBy('featured_position')->orderBy(self::fallbackLocalePath());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_minor_units' => 'integer',
            'compare_at_price_minor_units' => 'integer',
            'tax_rate_basis_points' => 'integer',
            'food_type' => FoodType::class,
            'availability' => ItemAvailability::class,
            'is_featured' => 'boolean',
            'featured_position' => 'integer',
            'position' => 'integer',
        ];
    }
}
