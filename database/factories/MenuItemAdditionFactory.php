<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\MenuItem;
use App\Models\MenuItemAddition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItemAddition>
 */
class MenuItemAdditionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // The item brings the tenant with it, for the same reason
        // MenuItemFactory takes its tenant from the category.
        $item = MenuItem::factory();

        $name = fake()->unique()->randomElement([
            'Extra cheese', 'Extra gravy', 'Large portion', 'Less spicy',
            'No onions', 'Extra raita', 'Add egg', 'Butter topping',
        ]).' '.fake()->unique()->numberBetween(1, 9999);

        return [
            'menu_item_id' => $item,
            'tenant_id' => fn (array $attributes): int => MenuItem::query()
                ->whereKey($attributes['menu_item_id'])
                ->value('tenant_id'),
            'name' => [Locale::English->value => $name],
            'price_minor_units' => fake()->numberBetween(0, 10000),
            'tax_rate_basis_points' => null,
            'is_available' => true,
            'position' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * Put this add-on on an existing item, and its tenant with it.
     */
    public function onItem(MenuItem $item): static
    {
        return $this->state(fn (array $attributes): array => [
            'menu_item_id' => $item->getKey(),
            'tenant_id' => $item->tenant_id,
        ]);
    }

    /**
     * An addition with every language filled in.
     */
    public function translated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => [
                Locale::English->value => $attributes['name'][Locale::English->value],
                Locale::Tamil->value => 'கூடுதல் '.fake()->unique()->numberBetween(1, 9999),
            ],
        ]);
    }

    /**
     * An addition that costs nothing, like "no onions".
     */
    public function free(): static
    {
        return $this->state(fn (array $attributes): array => [
            'price_minor_units' => 0,
        ]);
    }

    /**
     * An addition taxed at a rate of its own rather than the tenant's.
     *
     * Basis points, as stored: 1800 is 18%.
     */
    public function taxedAt(int $basisPoints): static
    {
        return $this->state(fn (array $attributes): array => [
            'tax_rate_basis_points' => $basisPoints,
        ]);
    }

    /**
     * Run out for the evening.
     */
    public function unavailable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_available' => false,
        ]);
    }
}
