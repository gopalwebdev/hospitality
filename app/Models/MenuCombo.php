<?php

namespace App\Models;

use App\Enums\ItemAvailability;
use App\Models\Concerns\HasTranslatedNames;
use App\Models\Concerns\IsPricedOnAMenu;
use Carbon\CarbonImmutable;
use Database\Factories\MenuComboFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bundle sold at one price: burger, fries and a drink for ₹299.
 *
 * Hangs off the menu rather than off a category, because that is what it is —
 * something the menu leads with, arranged in a row of its own beside the
 * featured dishes and dragged into order there. It is not a dish and does not
 * live in a section.
 *
 * Its price is its own. The dishes inside it are listed so a guest can see what
 * they are getting, not to be added up: a combo exists precisely because it
 * costs less than its parts, and repricing a dish must never quietly reprice
 * every combo containing it.
 *
 * The name and description are translated columns, and money is an integer
 * count of minor units, exactly as on MenuItem.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_id
 * @property-read Menu $menu
 * @property string $name
 * @property string|null $description
 * @property int $price_minor_units
 * @property int|null $compare_at_price_minor_units
 * @property int|null $tax_rate_basis_points
 * @property ItemAvailability $availability
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'menu_id',
    'name',
    'description',
    'price_minor_units',
    'compare_at_price_minor_units',
    'tax_rate_basis_points',
    'availability',
    'position',
])]
class MenuCombo extends Model
{
    /** @use HasFactory<MenuComboFactory> */
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
    ];

    /**
     * Take the tenant from the menu this sits on.
     *
     * The same reason as MenuCategory::booted(): a relation manager writes
     * these rows against a model Filament's tenancy is not stamping.
     */
    protected static function booted(): void
    {
        static::creating(function (self $combo): void {
            if (filled($combo->tenant_id) || blank($combo->menu_id)) {
                return;
            }

            $combo->tenant_id = Menu::query()
                ->withoutGlobalScopes()
                ->whereKey($combo->menu_id)
                ->value('tenant_id');
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
     * The menu this combo is offered on.
     *
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * What is in the bundle, with the quantity of each.
     *
     * @return HasMany<MenuComboItem, $this>
     */
    public function comboItems(): HasMany
    {
        return $this->hasMany(MenuComboItem::class);
    }

    /**
     * The dishes in the bundle, without the quantities.
     *
     * For reading names and food types in one query. When the quantity matters
     * — anywhere it is shown or ordered — use comboItems() instead.
     *
     * @return BelongsToMany<MenuItem, $this>
     */
    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_combo_items')
            ->withPivot(['quantity', 'position'])
            ->withTimestamps();
    }

    /**
     * What the dishes inside would cost bought separately.
     *
     * Only ever shown beside the combo price to make the saving concrete, and
     * never used as the price itself. Reads the loaded contents rather than
     * querying, so a list that eager loaded them costs nothing extra.
     */
    public function contentsPriceMinorUnits(): int
    {
        return $this->comboItems
            ->reduce(
                static fn (int $total, MenuComboItem $comboItem): int => $total
                    + ($comboItem->menuItem->price_minor_units * $comboItem->quantity),
                0,
            );
    }

    /**
     * Limit the query to combos a guest may actually order.
     *
     * Both halves matter, exactly as on MenuItem: a combo is orderable only if
     * it is available and the menu it sits on is showing.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOrderable(Builder $query): void
    {
        $query->whereIn('availability', ItemAvailability::orderableValues())
            ->whereRelation('menu', 'is_active', true);
    }

    /**
     * Order the way the tenant arranged them, name only to break ties.
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
            'price_minor_units' => 'integer',
            'compare_at_price_minor_units' => 'integer',
            'tax_rate_basis_points' => 'integer',
            'availability' => ItemAvailability::class,
            'position' => 'integer',
        ];
    }
}
