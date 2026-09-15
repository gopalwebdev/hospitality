<?php

namespace App\Models;

use App\Enums\OrderLineType;
use App\Models\Concerns\HasTranslatedNames;
use Carbon\CarbonImmutable;
use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of an order: an item or a combo, how many, and what it cost then — with the name the guest read, in every language.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property-read Order $order
 * @property OrderLineType $type
 * @property int|null $menu_item_id
 * @property int|null $menu_combo_id
 * @property string $name
 * @property int $quantity
 * @property int $unit_price_minor_units
 * @property int $total_minor_units
 * @property int $tax_rate_basis_points
 * @property int $position
 * @property-read Collection<int, OrderLineChoice> $choices
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'type',
    'menu_item_id',
    'menu_combo_id',
    'name',
    'quantity',
    'unit_price_minor_units',
    'total_minor_units',
    'tax_rate_basis_points',
    'position',
])]
class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    use HasTranslatedNames;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'position' => 0,
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /**
     * @return BelongsTo<MenuCombo, $this>
     */
    public function menuCombo(): BelongsTo
    {
        return $this->belongsTo(MenuCombo::class);
    }

    /**
     * The options picked for one of this line's item.
     *
     * @return HasMany<OrderLineChoice, $this>
     */
    public function choices(): HasMany
    {
        return $this->hasMany(OrderLineChoice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'type' => OrderLineType::class,
            'menu_item_id' => 'integer',
            'menu_combo_id' => 'integer',
            'quantity' => 'integer',
            'unit_price_minor_units' => 'integer',
            'total_minor_units' => 'integer',
            'tax_rate_basis_points' => 'integer',
            'position' => 'integer',
        ];
    }
}
