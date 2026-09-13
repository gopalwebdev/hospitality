<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuCategory>
 */
class MenuCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Starters', 'Soups', 'Biryani', 'Breads', 'Curries',
            'Rice', 'Desserts', 'Beverages', 'Tandoori', 'Chinese',
        ]).' '.fake()->unique()->numberBetween(1, 9999);

        return [
            // The tenant is chosen first and the menu follows it, rather
            // than the other way round, so that passing a tenant_id — which
            // most tests do — cannot produce a menu at a different tenant,
            // which MenuCategoryObserver would refuse.
            'tenant_id' => Tenant::factory(),
            'menu_id' => fn (array $attributes): int => Menu::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),
            'name' => [Locale::English->value => $name],
            // Top level unless a state says otherwise: most categories are.
            'parent_id' => null,
            'position' => fake()->numberBetween(0, 20),
            'is_active' => true,
        ];
    }

    /**
     * Put this section on an existing menu, and its tenant with it.
     */
    public function inMenu(Menu $menu): static
    {
        return $this->state(fn (array $attributes): array => [
            'menu_id' => $menu->getKey(),
            'tenant_id' => $menu->tenant_id,
        ]);
    }

    /**
     * Make this a subdivision of an existing category, menu and all.
     *
     * All three columns together, because a subdivision must sit on the same
     * menu as its parent — MenuCategoryObserver insists on it.
     */
    public function under(MenuCategory $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'parent_id' => $parent->getKey(),
            'menu_id' => $parent->menu_id,
            'tenant_id' => $parent->tenant_id,
        ]);
    }

    /**
     * A section with every language filled in.
     */
    public function translated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => [
                Locale::English->value => $attributes['name'][Locale::English->value],
                Locale::Tamil->value => 'பிரிவு '.fake()->unique()->numberBetween(1, 9999),
            ],
        ]);
    }

    /**
     * A category kept off the menu without being deleted.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
