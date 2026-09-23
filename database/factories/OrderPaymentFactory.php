<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * How much of one payment settled one order.
 *
 * A test settling an order reaches for PaymentFactory::settling() instead,
 * which writes this same shape alongside the payment it belongs to; this
 * factory exists so OrderPayment (like every model) has one of its own.
 *
 * @extends Factory<OrderPayment>
 */
class OrderPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'tenant_id' => fn (array $attributes): int => (int) Payment::query()
                ->whereKey($attributes['payment_id'])
                ->value('tenant_id'),
            'order_id' => Order::factory(),
            'amount' => fake()->numberBetween(1000, 50000),
        ];
    }

    /**
     * How much of an existing payment settled an existing order.
     */
    public function allocating(Payment $payment, Order $order, int $amount): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_id' => $payment->getKey(),
            'tenant_id' => $payment->tenant_id,
            'order_id' => $order->getKey(),
            'amount' => $amount,
        ]);
    }
}
