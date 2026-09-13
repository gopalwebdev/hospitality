<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatedNames;
use App\Observers\MenuCategoryObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MenuCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A section of a menu, or — with a parent — a subdivision of one. Two levels, no more.
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
#[ObservedBy([MenuCategoryObserver::class])]
class MenuCategory extends Model
{
    /** @use HasFactory<MenuCategoryFactory> */
    use HasFactory;

    use HasTranslatedNames;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'position' => 0,
        'is_active' => true,
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * @return BelongsTo<MenuCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MenuCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The dishes filed under this category itself, not under its subdivisions.
     *
     * @return HasMany<MenuItem, $this>
     */
    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function isTopLevel(): bool
    {
        return $this->parent_id === null;
    }

    public function isSubCategory(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * "Biryani", or "Biryani › Chicken" when the parent is loaded — never a lazy load.
     */
    public function path(string $separator = ' › '): string
    {
        $parent = $this->relationLoaded('parent') ? $this->getRelation('parent') : null;

        return $parent instanceof self ? $parent->name.$separator.$this->name : $this->name;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeSubCategories(Builder $query): void
    {
        $query->whereNotNull('parent_id');
    }

    /**
     * A subdivision shows only while its parent does.
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
