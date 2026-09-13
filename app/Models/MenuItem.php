<?php

namespace App\Models;

use App\Enums\FoodType;
use App\Enums\ItemAvailability;
use App\Models\Concerns\HasTranslatedNames;
use App\Models\Concerns\IsPricedOnAMenu;
use App\Observers\MenuItemObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dish, filed under exactly one category at either level. Prices are integer minor units.
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
#[ObservedBy([MenuItemObserver::class])]
class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory;

    use HasTranslatedNames;
    use IsPricedOnAMenu;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'position' => 0,
        'availability' => ItemAvailability::Available->value,
        'is_featured' => false,
        'featured_position' => 0,
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<MenuCategory, $this>
     */
    public function menuCategory(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class);
    }

    /**
     * @return HasMany<MenuItemAddition, $this>
     */
    public function additions(): HasMany
    {
        return $this->hasMany(MenuItemAddition::class);
    }

    /**
     * @return HasMany<MenuComboItem, $this>
     */
    public function comboItems(): HasMany
    {
        return $this->hasMany(MenuComboItem::class);
    }

    /**
     * @return BelongsToMany<MenuCombo, $this>
     */
    public function combos(): BelongsToMany
    {
        return $this->belongsToMany(MenuCombo::class, 'menu_combo_items')
            ->withPivot(['quantity', 'position'])
            ->withTimestamps();
    }

    public function isOrderable(): bool
    {
        return $this->availability->isOrderable();
    }

    /**
     * What a guest may order right now: available, under showing categories, on a showing menu.
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
     * @param  Builder<$this>  $query
     */
    public function scopeInMenuOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy(self::fallbackLocalePath());
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOnMenu(Builder $query, int $menuId): void
    {
        $query->whereRelation('menuCategory', 'menu_id', $menuId);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeFeaturedOnMenu(Builder $query, int $menuId): void
    {
        $query->where('is_featured', true)->onMenu($menuId);
    }

    /**
     * The order of the featured rail, separate from a dish's place in its category.
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
