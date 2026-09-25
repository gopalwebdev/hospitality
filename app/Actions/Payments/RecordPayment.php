<?php

namespace App\Actions\Payments;

use App\Enums\PaymentDeviceKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRefusal;
use App\Exceptions\PaymentRefused;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Payment;
use App\Models\PaymentDevice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Record money staff actually took, and how it is split across the orders it settles.
 *
 * The only writer of payments and order_payments. In one transaction, every
 * named order is locked FOR UPDATE in key order — the ApplyStockChanges
 * discipline, so two staff settling at once queue on the same rows rather than
 * deadlocking — and each order's outstanding amount is read fresh under that
 * lock, never from a value either side already had loaded.
 *
 * A method that names no device or reference (cash) has both cleared rather
 * than refused, the ChargeObserver pattern of clearing the column a
 * calculation does not use: a device handed in for cash is simply not the
 * point of cash, not a mistake worth stopping the payment for.
 */
final readonly class RecordPayment
{
    /**
     * @param  array<int, int>  $allocations  order id => how much of the payment settles it
     *
     * @throws PaymentRefused when an order is cancelled or foreign, an allocation
     *                        overpays it, the allocations do not add up, or the
     *                        device does not fit the method
     */
    public function __invoke(
        Tenant $tenant,
        PaymentMethod $method,
        int $amount,
        array $allocations,
        ?PaymentDevice $device = null,
        ?string $reference = null,
        ?string $note = null,
        ?User $recordedBy = null,
    ): Payment {
        return DB::transaction(function () use ($tenant, $method, $amount, $allocations, $device, $reference, $note, $recordedBy): Payment {
            $orderIds = array_keys($allocations);
            sort($orderIds);

            $locked = Order::query()
                ->withoutGlobalScopes()
                ->whereKey($orderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'tenant_id', 'status', 'total'])
                ->keyBy(fn (Order $order): int => $order->getKey());

            throw_if($allocations === [], PaymentRefused::class, PaymentRefusal::AllocationMismatch);

            $paid = $this->amountsAlreadyPaid($orderIds);

            foreach ($allocations as $orderId => $orderAmount) {
                throw_if($orderAmount <= 0, PaymentRefused::class, PaymentRefusal::AllocationMismatch);

                $order = $locked->get($orderId);

                throw_if($order === null || (int) $order->tenant_id !== $tenant->getKey(), PaymentRefused::class, PaymentRefusal::ForeignOrder, $orderId);

                throw_unless($order->isLive(), PaymentRefused::class, PaymentRefusal::OrderCancelled, $orderId);

                // Outstanding is worked out from the sums read under the lock
                // just taken — never a value either side may already have
                // loaded — so two payments racing to settle the same order
                // cannot both succeed.
                $outstanding = max(0, (int) $order->total - ($paid[$orderId] ?? 0));

                throw_if($orderAmount > $outstanding, PaymentRefused::class, PaymentRefusal::OverAllocated, $orderId);
            }

            throw_unless(array_sum($allocations) === $amount, PaymentRefused::class, PaymentRefusal::AllocationMismatch);

            $deviceKind = $method->deviceKind();
            $resolvedDevice = null;

            if ($device instanceof PaymentDevice && $deviceKind instanceof PaymentDeviceKind) {
                throw_unless(
                    (int) $device->tenant_id === $tenant->getKey() && $device->is_active && $device->kind === $deviceKind,
                    PaymentRefused::class,
                    PaymentRefusal::DeviceMismatch,
                );

                $resolvedDevice = $device;
            }

            $payment = new Payment([
                'method' => $method,
                'payment_device_id' => $resolvedDevice?->getKey(),
                'amount' => $amount,
                // A method that carries no reference has none stored, whatever
                // was typed — cash changes no hands through a machine.
                'reference' => $method->takesReference() ? $reference : null,
                'note' => $note,
                'recorded_by_user_id' => $recordedBy?->getKey(),
                'paid_at' => now(),
            ]);

            $payment->forceFill(['tenant_id' => $tenant->getKey()])->save();

            foreach ($allocations as $orderId => $orderAmount) {
                $orderPayment = new OrderPayment(['order_id' => $orderId, 'amount' => $orderAmount]);

                $orderPayment->forceFill(['tenant_id' => $tenant->getKey(), 'payment_id' => $payment->getKey()])->save();
            }

            return $payment;
        });
    }

    /**
     * What each of these orders has already been paid, ignoring a voided payment.
     *
     * One query however many orders are being settled, read inside the
     * transaction after the lock has been taken. Asking each order for its own
     * `amountPaid()` instead would be a query per order *inside a lock* — and
     * the same query the panel already ran to work the allocations out, which
     * the duplicate-query guard refuses outright (`.ai/rules/app.md`).
     *
     * Global scopes are off deliberately: the tenant is checked by hand above,
     * and a sum that silently skipped rows would let an order be paid twice.
     *
     * @param  list<int>  $orderIds
     * @return array<int, int>
     */
    private function amountsAlreadyPaid(array $orderIds): array
    {
        return OrderPayment::query()
            ->withoutGlobalScopes()
            ->whereIn('order_payments.order_id', $orderIds)
            ->whereExists(fn ($payment) => $payment
                ->from('payments')
                ->whereColumn('payments.id', 'order_payments.payment_id')
                ->whereNull('payments.voided_at'))
            ->groupBy('order_payments.order_id')
            ->selectRaw('order_payments.order_id as order_id, sum(order_payments.amount) as amount_paid')
            ->pluck('amount_paid', 'order_id')
            ->map(fn ($amount): int => (int) $amount)
            ->all();
    }
}
