<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Money staff recorded as taken from a guest — cash, UPI or a card.
 *
 * A test about RecordPayment's own arithmetic and locking calls the action
 * itself; this factory is for a test that only needs a payment to exist, or
 * to settle a particular order.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'method' => PaymentMethod::Cash,
            'payment_device_id' => null,
            'amount' => fake()->numberBetween(10000, 90000),
            'reference' => null,
            'note' => null,
            'recorded_by_user_id' => null,
            'paid_at' => now(),
            'voided_at' => null,
            'voided_by_user_id' => null,
            'void_reason' => null,
        ];
    }

    /**
     * A payment belonging to an existing tenant.
     */
    public function ofTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->getKey(),
        ]);
    }

    /**
     * Taken by this method, with a reference number when the method carries one.
     */
    public function withMethod(PaymentMethod $method): static
    {
        return $this->state(fn (array $attributes): array => [
            'method' => $method,
            'reference' => $method->takesReference() ? 'TXN'.fake()->numerify('########') : null,
        ]);
    }

    /**
     * Settling one or more existing orders: the amount is what they still owe
     * added up, and an order_payments row is written for each — the shape
     * App\Actions\Payments\RecordPayment itself writes, for a test that only
     * needs the result rather than the action's own locking and refusals.
     */
    public function settling(Order ...$orders): static
    {
        $amountOf = static fn (Order $order): int => $order->amountOutstanding() > 0
            ? $order->amountOutstanding()
            : $order->total;

        return $this
            ->state(fn (array $attributes): array => [
                'tenant_id' => $orders !== [] ? $orders[0]->tenant_id : $attributes['tenant_id'],
                'amount' => array_sum(array_map($amountOf, $orders)),
            ])
            ->afterCreating(function (Payment $payment) use ($orders, $amountOf): void {
                foreach ($orders as $order) {
                    // tenant_id is not passed: OrderPayment does not make it
                    // fillable, because OrderPaymentObserver takes it from the
                    // payment through InheritParentTenant.
                    $payment->allocations()->create([
                        'order_id' => $order->getKey(),
                        'amount' => $amountOf($order),
                    ]);
                }
            });
    }

    /**
     * Reversed: kept, but no longer live money.
     */
    public function voided(): static
    {
        return $this->state(fn (array $attributes): array => [
            'voided_at' => now(),
            'void_reason' => 'Refunded to the guest',
        ]);
    }
}
