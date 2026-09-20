<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\MenuAddOnGroup;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuAddOnGroup>
 */
class MenuAddOnGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // The number keeps the name unique; see MenuCategoryFactory for why the
        // word is not drawn with unique() too.
        $name = fake()->randomElement([
            'Spice level', 'Choose your bread', 'Extras', 'Portion', 'Sides', 'Sugar',
        ]).' '.fake()->unique()->numberBetween(1, 9999);

        // Optional by default: most groups are extras a guest may skip.
        return [
            'tenant_id' => Tenant::factory(),
            'name' => [Locale::English->value => $name],
            'is_required' => false,
            'max_picks' => null,
        ];
    }

    /**
     * Put this group in an existing tenant's library.
     */
    public function ofTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->getKey(),
        ]);
    }

    /**
     * A group a guest must or may pick from, at most $max times; a null $max is no limit.
     */
    public function choosing(bool $required, ?int $max): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_required' => $required,
            'max_picks' => $max,
        ]);
    }

    /**
     * A group with every language filled in.
     */
    public function translated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => [
                Locale::English->value => $attributes['name'][Locale::English->value],
                Locale::Tamil->value => 'தேர்வு '.fake()->unique()->numberBetween(1, 9999),
            ],
        ]);
    }
}
