<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatedNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A charge an order's bill carried — a service charge, a room-service fee — at the amount it came to then.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property-read Order $order
 * @property int|null $charge_id
 * @property string $name
 * @property int $amount
 * @property int $tax_rate
 * @property int $taxable_value
 * @property int $cgst_rate
 * @property int $cgst
 * @property int $sgst_rate
 * @property int $sgst
 * @property string|null $hsn_sac_code
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'charge_id',
    'name',
    'amount',
    'tax_rate',
    'taxable_value',
    'cgst_rate',
    'cgst',
    'sgst_rate',
    'sgst',
    'hsn_sac_code',
    'position',
])]
class OrderCharge extends Model
{
    use HasTranslatedNames;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'position' => 0,
        'tax_rate' => 0,
        'taxable_value' => 0,
        'cgst_rate' => 0,
        'cgst' => 0,
        'sgst_rate' => 0,
        'sgst' => 0,
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'charge_id' => 'integer',
            'amount' => 'integer',
            'tax_rate' => 'integer',
            'taxable_value' => 'integer',
            'cgst_rate' => 'integer',
            'cgst' => 'integer',
            'sgst_rate' => 'integer',
            'sgst' => 'integer',
            'position' => 'integer',
        ];
    }
}
