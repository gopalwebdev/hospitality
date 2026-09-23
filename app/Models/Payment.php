<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money staff recorded as taken from a guest: cash, UPI, or a card, possibly
 * settling several orders at once through order_payments.
 *
 * A payment is never hard-deleted, only voided — the history is the point.
 * Order::amountPaid() and the settled/unsettled scopes on Order both ignore a
 * voided payment, so voiding brings the outstanding amount straight back.
 *
 * @property int $id
 * @property int $tenant_id
 * @property PaymentMethod $method
 * @property int|null $payment_device_id
 * @property-read PaymentDevice|null $paymentDevice
 * @property int $amount minor units, the whole transaction
 * @property string|null $reference transaction / UTR / approval number
 * @property string|null $note
 * @property int|null $recorded_by_user_id
 * @property-read User|null $recordedBy
 * @property CarbonImmutable $paid_at
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by_user_id
 * @property string|null $void_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, OrderPayment> $allocations
 * @property-read Collection<int, Order> $orders
 */
#[Fillable([
    'method',
    'payment_device_id',
    'amount',
    'reference',
    'note',
    'recorded_by_user_id',
    'paid_at',
    'voided_at',
    'voided_by_user_id',
    'void_reason',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<PaymentDevice, $this>
     */
    public function paymentDevice(): BelongsTo
    {
        return $this->belongsTo(PaymentDevice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * How this payment is split across the orders it settles.
     *
     * @return HasMany<OrderPayment, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    /**
     * The orders this payment settles, with how much of it went to each.
     *
     * @return BelongsToMany<Order, $this>
     */
    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'order_payments')->withPivot('amount')->withTimestamps();
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'payment_device_id' => 'integer',
            'amount' => 'integer',
            'recorded_by_user_id' => 'integer',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'voided_by_user_id' => 'integer',
        ];
    }
}
