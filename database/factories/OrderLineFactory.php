<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Enums\OrderLineType;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A line of an order, naming nothing on the menu: the copy of what was ordered is what an order keeps.
 *
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 3);
        $price = fake()->numberBetween(5000, 40000);

        return [
            'order_id' => Order::factory(),
            'tenant_id' => fn (array $attributes): int => (int) Order::query()
                ->whereKey($attributes['order_id'])
                ->value('tenant_id'),
            'type' => OrderLineType::Item,
            'menu_item_id' => null,
            'menu_combo_id' => null,
            'name' => [Locale::English->value => ucfirst(fake()->word())],
            'quantity' => $quantity,
            'unit_price' => $price,
            'total' => $price * $quantity,
            // Levied in halves, as order_lines_tax_rates_add_up insists.
            'tax_rate' => 500,
            'taxable_value' => $price * $quantity,
            'cgst_rate' => 250,
            'cgst' => intdiv($price * $quantity * 250, 10000),
            'sgst_rate' => 250,
            'sgst' => intdiv($price * $quantity * 250, 10000),
            'igst_rate' => 0,
            'igst' => 0,
            'hsn_sac_code' => null,
            'position' => 0,
        ];
    }

    /**
     * A line of an existing order, and its tenant with it.
     */
    public function inOrder(Order $order): static
    {
        return $this->state(fn (array $attributes): array => [
            'order_id' => $order->getKey(),
            'tenant_id' => $order->tenant_id,
        ]);
    }
}
