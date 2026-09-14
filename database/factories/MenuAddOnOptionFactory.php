<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuAddOnOption>
 */
class MenuAddOnOptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // The group brings the tenant with it, for the same reason
        // MenuItemFactory takes its tenant from the category.
        $group = MenuAddOnGroup::factory();

        // The number keeps the name unique; see MenuCategoryFactory for why the
        // word is not drawn with unique() too.
        $name = fake()->randomElement([
            'Extra cheese', 'Garlic naan', 'Mild', 'Raita', 'Ghee', 'Less sugar',
        ]).' '.fake()->unique()->numberBetween(1, 9999);

        return [
            'menu_add_on_group_id' => $group,
            'tenant_id' => fn (array $attributes): int => (int) MenuAddOnGroup::query()
                ->whereKey($attributes['menu_add_on_group_id'])
                ->value('tenant_id'),
            'name' => [Locale::English->value => $name],
            'price_minor_units' => fake()->numberBetween(0, 10000),
            'tax_rate_basis_points' => null,
            'max_quantity' => 1,
            'is_preselected' => false,
            'is_available' => true,
            'position' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * Put this option in an existing group, and its tenant with it.
     */
    public function inGroup(MenuAddOnGroup $group): static
    {
        return $this->state(fn (array $attributes): array => [
            'menu_add_on_group_id' => $group->getKey(),
            'tenant_id' => $group->tenant_id,
        ]);
    }

    /**
     * An option that costs nothing, like "mild".
     */
    public function free(): static
    {
        return $this->state(fn (array $attributes): array => [
            'price_minor_units' => 0,
        ]);
    }

    /**
     * An option a guest may take more than one of.
     */
    public function upTo(int $quantity): static
    {
        return $this->state(fn (array $attributes): array => [
            'max_quantity' => $quantity,
        ]);
    }

    /**
     * Ticked before the guest touches anything.
     */
    public function preselected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_preselected' => true,
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

    /**
     * An option taxed at a rate of its own rather than the tenant's.
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
     * An option with every language filled in.
     */
    public function translated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => [
                Locale::English->value => $attributes['name'][Locale::English->value],
                Locale::Tamil->value => 'விருப்பம் '.fake()->unique()->numberBetween(1, 9999),
            ],
        ]);
    }
}
