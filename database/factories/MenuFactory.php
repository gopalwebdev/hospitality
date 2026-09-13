<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\Menu;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Menu>
 */
class MenuFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Lunch', 'Dinner', 'Breakfast', 'Drinks', 'Desserts', 'Weekend Special',
        ]).' '.fake()->unique()->numberBetween(1, 9999);

        // English only by default: a menu with no Tamil copy yet is the normal
        // state of a tenant that has just been onboarded, and the guest app
        // has to read correctly in that state.
        return [
            'tenant_id' => Tenant::factory(),
            'name' => [Locale::English->value => $name],
            'description' => null,
            'position' => fake()->numberBetween(0, 20),
            'is_active' => true,
            // No window by default: most menus are served whenever the
            // tenant is open, and servedBetween() is for the ones that are
            // not.
            'available_from' => null,
            'available_until' => null,
        ];
    }

    /**
     * A menu with every language filled in.
     */
    public function translated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => [
                Locale::English->value => $attributes['name'][Locale::English->value],
                Locale::Tamil->value => 'மெனு '.fake()->unique()->numberBetween(1, 9999),
            ],
        ]);
    }

    /**
     * A menu served only between two times of day.
     *
     * Pass them the way they are stored, as HH:MM — "07:00", "11:00" for a
     * breakfast card. A window running backwards past midnight is allowed and
     * is read that way; see Menu::isBeingServedAt().
     */
    public function servedBetween(string $from, string $until): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_from' => $from,
            'available_until' => $until,
        ]);
    }

    /**
     * A menu kept off the storefront without being deleted.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
