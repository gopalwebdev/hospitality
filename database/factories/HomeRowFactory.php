<?php

namespace Database\Factories;

use App\Enums\HomeRowLayout;
use App\Enums\Locale;
use App\Models\HomeRow;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeRow>
 */
class HomeRowFactory extends Factory
{
    /**
     * A banner row, which is the one a tenant leads with.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'title' => null,
            'layout' => HomeRowLayout::Banner,
            'position' => fake()->numberBetween(0, 20),
            'is_active' => true,
        ];
    }

    /**
     * A row on an existing tenant's home screen.
     */
    public function ofTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->getKey(),
        ]);
    }

    /**
     * A row drawn with the given layout.
     */
    public function layout(HomeRowLayout $layout): static
    {
        return $this->state(fn (array $attributes): array => [
            'layout' => $layout,
        ]);
    }

    /**
     * A row with a heading over it, in English alone.
     */
    public function titled(string $title): static
    {
        return $this->state(fn (array $attributes): array => [
            'title' => [Locale::English->value => $title],
        ]);
    }

    /**
     * A row taken off the home screen without being deleted.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
