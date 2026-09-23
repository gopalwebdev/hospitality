<?php

namespace App\Models;

use App\Observers\OrderPaymentObserver;
use Carbon\CarbonImmutable;
use Database\Factories\OrderPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one payment settled one order. A real entity rather than a bare
 * pivot, because it carries an amount — one payment may settle several
 * orders, and one order may be settled by several payments.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $payment_id
 * @property-read Payment $payment
 * @property int $order_id
 * @property-read Order $order
 * @property int $amount how much of the payment settled this order, minor units
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['payment_id', 'order_id', 'amount'])]
#[ObservedBy([OrderPaymentObserver::class])]
class OrderPayment extends Model
{
    /** @use HasFactory<OrderPaymentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'order_id' => 'integer',
            'amount' => 'integer',
        ];
    }
}
