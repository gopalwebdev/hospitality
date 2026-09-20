<?php

namespace Database\Factories;

use App\Enums\MenuRailType;
use App\Models\Menu;
use App\Models\MenuRail;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuRail>
 */
class MenuRailFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // The tenant first and the menu from it, as MenuComboFactory does,
            // so passing a tenant_id cannot put the rail on another's menu.
            'tenant_id' => Tenant::factory(),
            'menu_id' => fn (array $attributes): int => Menu::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),
            'type' => MenuRailType::Featured,
            'position' => fake()->numberBetween(0, 20),
        ];
    }

    /**
     * Place this rail on an existing menu, and its tenant with it.
     */
    public function onMenu(Menu $menu): static
    {
        return $this->state(fn (array $attributes): array => [
            'menu_id' => $menu->getKey(),
            'tenant_id' => $menu->tenant_id,
        ]);
    }

    /**
     * A rail of one kind.
     */
    public function ofType(MenuRailType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }
}
