<?php

namespace Database\Factories;

use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuComboItem;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuComboItem>
 */
class MenuComboItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Everything is derived from the combo, and nothing picks a tenant
        // of its own. MenuItem::factory() on its own would build a category —
        // and with it a menu and a tenant — that has nothing to do with
        // this combo, and overriding tenant_id afterwards only makes the two
        // halves of the composite key disagree. That is the trap
        // .ai/rules/models.md warns about, and it fails as a foreign key
        // violation rather than as anything that names the cause.
        return [
            'menu_combo_id' => MenuCombo::factory(),
            'tenant_id' => fn (array $attributes): int => $this->combo($attributes)->tenant_id,
            'menu_item_id' => fn (array $attributes): int => $this->dishOnSameMenu($this->combo($attributes))->getKey(),
            'quantity' => 1,
            'position' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * Put an existing dish into an existing combo.
     *
     * Both must belong to one tenant; the composite foreign keys refuse
     * anything else, which is what makes this the only safe way to pair two
     * records a test already has.
     */
    public function pairing(MenuCombo $combo, MenuItem $item): static
    {
        return $this->state(fn (array $attributes): array => [
            'menu_combo_id' => $combo->getKey(),
            'menu_item_id' => $item->getKey(),
            'tenant_id' => $combo->tenant_id,
        ]);
    }

    /**
     * More than one of the same dish in the bundle.
     */
    public function quantity(int $quantity): static
    {
        return $this->state(fn (array $attributes): array => [
            'quantity' => $quantity,
        ]);
    }

    /**
     * The combo this line is being written against.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function combo(array $attributes): MenuCombo
    {
        return MenuCombo::query()->whereKey($attributes['menu_combo_id'])->firstOrFail();
    }

    /**
     * A new dish in a new category of the combo's own menu.
     *
     * The menu is fetched rather than read off $combo->menu, which would be a
     * lazy load and throws under Model::shouldBeStrict().
     */
    private function dishOnSameMenu(MenuCombo $combo): MenuItem
    {
        $menu = Menu::query()->whereKey($combo->menu_id)->firstOrFail();

        return MenuItem::factory()
            ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
            ->create();
    }
}
