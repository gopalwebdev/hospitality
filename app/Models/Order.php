<?php

namespace App\Models;

use App\Enums\OrderSettlement;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Models\Concerns\HasTranslatedNames;
use App\Models\Concerns\ReadsLoadedCounts;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a guest ordered from one menu, priced and taken from stock when it was placed.
 * Its names and money are copies, so renaming or repricing an item never rewrites an order.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $menu_id
 * @property-read Menu|null $menu
 * @property int|null $location_id
 * @property-read Location|null $location
 * @property OrderStatus $status
 * @property string|null $location_name copy of the picked location's name, or the guest's free text
 * @property OrderSettlement $settlement the guest's intent to pay now or add it to the bill
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
 * @property-read Collection<int, OrderPayment> $paymentAllocations
 * @property-read Collection<int, Payment> $payments
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'status',
    'location_name',
    'settlement',
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

    use HasTranslatedNames;
    use ReadsLoadedCounts;

    /** @var list<string> */
    public array $translatable = ['location_name'];

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'status' => OrderStatus::Placed->value,
        'settlement' => OrderSettlement::AddToBill->value,
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
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
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

    /**
     * How this order's total has been settled, one row per payment that touched it.
     *
     * @return HasMany<OrderPayment, $this>
     */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    /**
     * The payments that settled this order, with how much of each went to it.
     *
     * @return BelongsToMany<Payment, $this>
     */
    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'order_payments')->withPivot('amount')->withTimestamps();
    }

    public function isPlaced(): bool
    {
        return $this->status === OrderStatus::Placed;
    }

    /**
     * How much of this order's total has been paid, ignoring a voided payment.
     *
     * Prefers a withSum(['paymentAllocations as amount_paid' => ...], 'amount')
     * value already loaded for the page (.ai/rules/models.md) — a list query
     * must alias its sum exactly `amount_paid` and constrain it to live
     * payments for this to read right — and falls back to a fresh query
     * otherwise. RecordPayment sums fresh under its own lock rather than
     * trusting either.
     */
    public function amountPaid(): int
    {
        return $this->loadedCount('amount_paid')
            ?? (int) $this->paymentAllocations()
                ->whereHas('payment', fn (Builder $payment): Builder => $payment->live())
                ->sum('amount');
    }

    /**
     * Whether a list query's `amount_paid` sum is already on this instance.
     *
     * The one honest way to ask, because loadedCount() answers null both for
     * "not loaded" and for a sum of nothing, and a modal opening an order has
     * to know which of those it is looking at before it decides to re-read.
     */
    public function amountPaidWasLoaded(): bool
    {
        return array_key_exists('amount_paid', $this->getAttributes());
    }

    /**
     * What is still owed: never negative, however an overpayment came about.
     */
    public function amountOutstanding(): int
    {
        return max(0, $this->total - $this->amountPaid());
    }

    /**
     * Unpaid, partly paid, or paid in full. Not a column — worked out from
     * amountPaid() against the total.
     */
    public function paymentState(): PaymentState
    {
        $amountPaid = $this->amountPaid();

        return match (true) {
            $amountPaid <= 0 => PaymentState::Unpaid,
            $amountPaid < $this->total => PaymentState::PartlyPaid,
            default => PaymentState::Paid,
        };
    }

    /**
     * What an order row has been paid, as SQL against `orders`.
     *
     * A correlated subquery over order_payments joined to live payments —
     * the MenuItemsTable::inMenuOrder() precedent for a correlated read in
     * raw SQL, needed because a plain whereHas cannot compare a sum against
     * another column on the same row. One method rather than the same string
     * typed out in each place, because a board summing what a whole location
     * still owes needs the very same expression the two scopes below compare
     * against, and three copies of it is three chances to diverge.
     *
     * @return literal-string
     */
    public static function amountPaidExpression(): string
    {
        return 'coalesce(('
            .'select sum(order_payments.amount) from order_payments'
            .' join payments on payments.id = order_payments.payment_id'
            .' where order_payments.order_id = orders.id and payments.voided_at is null'
            .'), 0)';
    }

    /**
     * Orders still owing money: the sum of their live (non-voided) payment
     * allocations comes to less than the order's own total.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeUnsettled(Builder $query): void
    {
        $query->whereRaw(self::amountPaidExpression().' < orders.total');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeSettled(Builder $query): void
    {
        $query->whereRaw(self::amountPaidExpression().' >= orders.total');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'menu_id' => 'integer',
            'location_id' => 'integer',
            'status' => OrderStatus::class,
            'settlement' => OrderSettlement::class,
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
