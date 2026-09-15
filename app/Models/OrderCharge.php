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
 * @property int $amount_minor_units
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['charge_id', 'name', 'amount_minor_units', 'position'])]
class OrderCharge extends Model
{
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
            'amount_minor_units' => 'integer',
            'position' => 'integer',
        ];
    }
}
