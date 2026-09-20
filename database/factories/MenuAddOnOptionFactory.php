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
            'price' => fake()->numberBetween(0, 10000),
            'max_per_item' => 1,
            'is_default' => false,
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
            'price' => 0,
        ]);
    }

    /**
     * An option a guest may take more than one of, up to $quantity.
     */
    public function upTo(int $quantity): static
    {
        return $this->state(fn (array $attributes): array => [
            'max_per_item' => $quantity,
        ]);
    }

    /**
     * An option someone counts, with $count left across every item offering it.
     */
    public function stocked(int $count): static
    {
        return $this->state(fn (array $attributes): array => [
            'stock_quantity' => $count,
        ]);
    }

    /**
     * Ticked for the guest when they open the item.
     */
    public function asDefault(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
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
