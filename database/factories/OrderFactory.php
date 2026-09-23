<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Enums\OrderSettlement;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Location;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An order as it is stored — its totals, not its lines.
 *
 * Orders are written by App\Actions\Orders\PlaceOrder, which takes from stock
 * as it writes. A test about stock places an order through that action; this
 * factory is for tests that only need an order to exist.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(10000, 90000);
        $tax = intdiv($subtotal * 5, 100);

        // Split the way PlaceOrder splits it, because orders_tax_parts_add_up
        // makes the database insist the parts come to the total.
        $cgst = intdiv($tax, 2);

        // The menu brings the tenant with it.
        return [
            'menu_id' => Menu::factory(),
            'tenant_id' => fn (array $attributes): int => (int) Menu::query()
                ->whereKey($attributes['menu_id'])
                ->value('tenant_id'),
            'status' => OrderStatus::Placed,
            'location_id' => null,
            // Free text under the default locale, the shape PlaceOrder stores
            // when no location was picked.
            'location_name' => [Locale::default()->value => 'Room '.fake()->numberBetween(101, 420)],
            'settlement' => OrderSettlement::AddToBill,
            'note' => null,
            'subtotal' => $subtotal,
            'is_union_territory' => false,
            'tax' => $tax,
            'cgst' => $cgst,
            'sgst' => $tax - $cgst,
            'charges_total' => 0,
            'total' => $subtotal + $tax,
            'prices_include_tax' => false,
            'cancelled_at' => null,
        ];
    }

    /**
     * Ordered from an existing menu, and its tenant with it.
     */
    public function onMenu(Menu $menu): static
    {
        return $this->state(fn (array $attributes): array => [
            'menu_id' => $menu->getKey(),
            'tenant_id' => $menu->tenant_id,
        ]);
    }

    /**
     * Called off.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Ordered at an existing location, copying its name in every language it has.
     */
    public function at(Location $location): static
    {
        return $this->state(fn (array $attributes): array => [
            'location_id' => $location->getKey(),
            'location_name' => $location->getTranslations('name'),
        ]);
    }

    /**
     * The guest's intent to pay straight away, rather than add it to the bill.
     */
    public function payNow(): static
    {
        return $this->state(fn (array $attributes): array => [
            'settlement' => OrderSettlement::PayNow,
        ]);
    }

    /**
     * Settled in full by one cash payment, created alongside the order.
     */
    public function paid(): static
    {
        return $this->afterCreating(function (Order $order): void {
            $payment = Payment::factory()
                ->withMethod(PaymentMethod::Cash)
                ->create(['tenant_id' => $order->tenant_id, 'amount' => $order->total]);

            $payment->allocations()->create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->getKey(),
                'amount' => $order->total,
            ]);
        });
    }

    /**
     * Settled for less than its total by one cash payment, still owing the rest.
     */
    public function partlyPaid(): static
    {
        return $this->afterCreating(function (Order $order): void {
            $amount = intdiv($order->total, 2);

            $payment = Payment::factory()
                ->withMethod(PaymentMethod::Cash)
                ->create(['tenant_id' => $order->tenant_id, 'amount' => $amount]);

            $payment->allocations()->create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->getKey(),
                'amount' => $amount,
            ]);
        });
    }
}
