<?php

namespace Database\Factories;

use App\Models\MenuAddOnGroup;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItemAddOnGroup>
 */
class MenuItemAddOnGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Everything is derived from the item, and the group is made in the
        // item's own tenant: one from any other would be refused by
        // MenuItemAddOnGroupObserver.
        return [
            'menu_item_id' => MenuItem::factory(),
            'tenant_id' => fn (array $attributes): int => (int) MenuItem::query()
                ->whereKey($attributes['menu_item_id'])
                ->value('tenant_id'),
            'menu_add_on_group_id' => fn (array $attributes): int => MenuAddOnGroup::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),
            'position' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * Offer an existing group on an existing item.
     *
     * Both must belong to one tenant; MenuItemAddOnGroupObserver refuses
     * anything else.
     */
    public function linking(MenuItem $item, MenuAddOnGroup $group): static
    {
        return $this->state(fn (array $attributes): array => [
            'menu_item_id' => $item->getKey(),
            'menu_add_on_group_id' => $group->getKey(),
            'tenant_id' => $item->tenant_id,
        ]);
    }

    /**
     * Cap this item's own picks from the group, tighter or looser than the group's own maximum.
     */
    public function capping(int $maxPicks): static
    {
        return $this->state(fn (array $attributes): array => [
            'max_picks' => $maxPicks,
        ]);
    }
}
