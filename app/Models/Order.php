<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a guest ordered from one menu, priced and taken from stock when it was placed.
 * Its names and money are copies, so renaming or repricing an item never rewrites an order.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $menu_id
 * @property-read Menu|null $menu
 * @property OrderStatus $status
 * @property string|null $location_label
 * @property string|null $note
 * @property int $subtotal
 * @property bool $is_union_territory
 * @property int $tax
 * @property int $cgst
 * @property int $sgst
 * @property int $charges_total
 * @property int $total
 * @property bool $prices_include_tax
 * @property CarbonImmutable|null $cancelled_at
 * @property-read Collection<int, OrderLine> $lines
 * @property-read Collection<int, OrderCharge> $charges
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'status',
    'location_label',
    'note',
    'subtotal',
    'is_union_territory',
    'tax',
    'cgst',
    'sgst',
    'charges_total',
    'total',
    'prices_include_tax',
    'cancelled_at',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'status' => OrderStatus::Placed->value,
        'is_union_territory' => false,
        'cgst' => 0,
        'sgst' => 0,
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
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /**
     * @return HasMany<OrderCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(OrderCharge::class);
    }

    /**
     * What the order took from stock, and what cancelling it put back.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isPlaced(): bool
    {
        return $this->status === OrderStatus::Placed;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'menu_id' => 'integer',
            'status' => OrderStatus::class,
            'subtotal' => 'integer',
            'is_union_territory' => 'boolean',
            'tax' => 'integer',
            'cgst' => 'integer',
            'sgst' => 'integer',
            'charges_total' => 'integer',
            'total' => 'integer',
            'prices_include_tax' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }
}
