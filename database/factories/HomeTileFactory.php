<?php

namespace Database\Factories;

use App\Enums\HomeTileAction;
use App\Enums\Locale;
use App\Models\HomeRow;
use App\Models\HomeTile;
use App\Models\Menu;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeTile>
 */
class HomeTileFactory extends Factory
{
    /**
     * A tile opening one of its tenant's menus, which is the common case.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'home_row_id' => fn (array $attributes): int => HomeRow::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),
            'menu_id' => fn (array $attributes): int => Menu::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),
            'label' => [Locale::English->value => 'Menu '.fake()->unique()->numberBetween(1, 9999)],
            'image_path' => 'home-tiles/'.fake()->uuid().'.jpg',
            'document_path' => null,
            'action' => HomeTileAction::Menu,
            'position' => fake()->numberBetween(0, 20),
            'is_active' => true,
        ];
    }

    /**
     * A tile in an existing row, and that row's tenant with it.
     *
     * The pair has to be set together or the composite foreign key trips —
     * see .ai/rules/models.md.
     */
    public function inRow(HomeRow $row): static
    {
        return $this->state(fn (array $attributes): array => [
            'home_row_id' => $row->getKey(),
            'tenant_id' => $row->tenant_id,
        ]);
    }

    /**
     * A tile opening an existing menu, and its tenant with it.
     */
    public function openingMenu(Menu $menu): static
    {
        return $this->state(fn (array $attributes): array => [
            'action' => HomeTileAction::Menu,
            'menu_id' => $menu->getKey(),
            'tenant_id' => $menu->tenant_id,
            'home_row_id' => fn (array $attributes): int => HomeRow::factory()
                ->create(['tenant_id' => $menu->tenant_id])
                ->getKey(),
            'document_path' => null,
            'url' => null,
        ]);
    }

    /**
     * A tile leaving the app for somewhere the tenant is also found.
     */
    public function linkingTo(?string $url = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'action' => HomeTileAction::Link,
            'url' => $url ?? fake()->url(),
            'menu_id' => null,
            'document_path' => null,
        ]);
    }

    /**
     * A tile showing an uploaded PDF instead of a menu.
     */
    public function showingPdf(?string $path = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'action' => HomeTileAction::Pdf,
            'document_path' => $path ?? 'home-tiles/'.fake()->uuid().'.pdf',
            'menu_id' => null,
            'url' => null,
        ]);
    }

    /**
     * A tile with every language filled in.
     */
    public function translated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'label' => [
                Locale::English->value => $attributes['label'][Locale::English->value],
                Locale::Tamil->value => 'மெனு '.fake()->unique()->numberBetween(1, 9999),
            ],
        ]);
    }

    /**
     * A tile with no picture yet, drawn as its label on the brand colour.
     */
    public function withoutImage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'image_path' => null,
        ]);
    }

    /**
     * A tile taken off the home screen without being deleted.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
