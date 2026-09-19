<?php

namespace App\Models;

use App\Enums\Diet;
use App\Enums\ItemAvailability;
use App\Models\Concerns\HasTranslatedNames;
use App\Models\Concerns\IsPricedOnAMenu;
use App\Observers\MenuItemObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One item on a menu — something to order, or a service request — filed under exactly one category at either level.
 * Prices are integer minor units; a service request carries no diet mark, and everything else carries at least one.
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
 * @property bool $is_service_request
 * @property Collection<int, Diet>|null $diets
 * @property ItemAvailability $availability
 * @property int|null $max_quantity
 * @property int|null $stock_quantity
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
    'is_service_request',
    'diets',
    'availability',
    'max_quantity',
    'stock_quantity',
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

    /**
     * is_service_request and stock_quantity are mirrored here because MenuItemObserver reads them before and after the row is inserted.
     *
     * @var array<string, mixed>
     */
    #[\Override]
    protected $attributes = [
        'position' => 0,
        'is_service_request' => false,
        'availability' => ItemAvailability::Available->value,
        'stock_quantity' => null,
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
     * The add-on groups a guest customises this item with, one link per group in this item's own order.
     *
     * @return HasMany<MenuItemAddOnGroup, $this>
     */
    public function addOnGroupLinks(): HasMany
    {
        return $this->hasMany(MenuItemAddOnGroup::class);
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

    /**
     * Every change to its count, and why.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isOrderable(): bool
    {
        return $this->availability->isOrderable();
    }

    /**
     * Whether anyone counts how many are left; one nobody counts never runs out.
     */
    public function tracksStock(): bool
    {
        return $this->stock_quantity !== null;
    }

    /**
     * Whether it costs a guest nothing — an extra pillow, a glass of water.
     */
    public function isComplimentary(): bool
    {
        return $this->price_minor_units === 0;
    }

    /**
     * The one diet mark a guest reads beside this item, or null for a service request.
     *
     * An item may carry several — vegetarian and vegan together — but the menu
     * shows the strictest of them, which already says everything the others do.
     */
    public function dietMark(): ?Diet
    {
        return Diet::strictest($this->diets ?? []);
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
     * The order of the featured rail, separate from an item's place in its category.
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
            'is_service_request' => 'boolean',
            'diets' => AsEnumCollection::of(Diet::class),
            'availability' => ItemAvailability::class,
            'max_quantity' => 'integer',
            'stock_quantity' => 'integer',
            'is_featured' => 'boolean',
            'featured_position' => 'integer',
            'position' => 'integer',
        ];
    }
}
