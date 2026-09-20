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
 * @property int $unit_price
 * @property int $total
 * @property int $tax_rate
 * @property int $taxable_value
 * @property int $cgst_rate
 * @property int $cgst
 * @property int $sgst_rate
 * @property int $sgst
 * @property int $igst_rate
 * @property int $igst
 * @property string|null $hsn_sac_code
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
    'unit_price',
    'total',
    'tax_rate',
    'taxable_value',
    'cgst_rate',
    'cgst',
    'sgst_rate',
    'sgst',
    'igst_rate',
    'igst',
    'hsn_sac_code',
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
        'taxable_value' => 0,
        'cgst_rate' => 0,
        'cgst' => 0,
        'sgst_rate' => 0,
        'sgst' => 0,
        'igst_rate' => 0,
        'igst' => 0,
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
            'unit_price' => 'integer',
            'total' => 'integer',
            'tax_rate' => 'integer',
            'taxable_value' => 'integer',
            'cgst_rate' => 'integer',
            'cgst' => 'integer',
            'sgst_rate' => 'integer',
            'sgst' => 'integer',
            'igst_rate' => 'integer',
            'igst' => 'integer',
            'position' => 'integer',
        ];
    }
}
