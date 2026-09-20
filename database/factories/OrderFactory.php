<?php

namespace Database\Factories;

use App\Enums\GstTreatment;
use App\Enums\OrderStatus;
use App\Models\Menu;
use App\Models\Order;
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
            'location_label' => 'Room '.fake()->numberBetween(101, 420),
            'note' => null,
            'subtotal' => $subtotal,
            'gst_treatment' => GstTreatment::IntraState,
            'tax' => $tax,
            'cgst' => $cgst,
            'sgst' => $tax - $cgst,
            'igst' => 0,
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
}
