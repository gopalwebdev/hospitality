<?php

namespace Database\Factories;

use App\Enums\ItemAvailability;
use App\Enums\Locale;
use App\Models\Menu;
use App\Models\MenuCombo;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuCombo>
 */
class MenuComboFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Family Feast', 'Lunch Box', 'Burger Meal', 'Biryani Combo',
            'Snack Pack', 'Dinner for Two', 'Party Platter',
        ]).' '.fake()->unique()->numberBetween(1, 9999);

        return [
            // The tenant is chosen first and the menu follows it, exactly
            // as MenuCategoryFactory does, so that passing a tenant_id cannot
            // produce a menu at a different tenant and trip the composite
            // foreign key.
            'tenant_id' => Tenant::factory(),
            'menu_id' => fn (array $attributes): int => Menu::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),
            'name' => [Locale::English->value => $name],
            'description' => [Locale::English->value => fake()->sentence()],
            'price_minor_units' => fake()->numberBetween(20000, 120000),
            'compare_at_price_minor_units' => null,
            'tax_rate_basis_points' => null,
            'availability' => ItemAvailability::Available,
            'position' => fake()->numberBetween(0, 20),
        ];
    }

    /**
     * Offer this combo on an existing menu, and its tenant with it.
     */
    public function onMenu(Menu $menu): static
    {
        return $this->state(fn (array $attributes): array => [
            'menu_id' => $menu->getKey(),
            'tenant_id' => $menu->tenant_id,
        ]);
    }

    /**
     * A combo with every language filled in.
     */
    public function translated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => [
                Locale::English->value => $attributes['name'][Locale::English->value],
                Locale::Tamil->value => 'சேர்க்கை '.fake()->unique()->numberBetween(1, 9999),
            ],
            'description' => [
                Locale::English->value => $attributes['description'][Locale::English->value],
                Locale::Tamil->value => 'சிறந்த சேர்க்கை.',
            ],
        ]);
    }

    /**
     * A combo advertised with a higher price struck through beside it.
     */
    public function discounted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'compare_at_price_minor_units' => $attributes['price_minor_units'] + 5000,
        ]);
    }

    /**
     * A combo taxed at a rate of its own rather than the tenant's default.
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
     * A combo that cannot be ordered right now.
     */
    public function unavailable(ItemAvailability $reason = ItemAvailability::OutOfStock): static
    {
        return $this->state(fn (array $attributes): array => [
            'availability' => $reason,
        ]);
    }
}
