<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Enums\LocationKind;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Where an order goes: a room, a table, or a delivery point.
 *
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'kind' => LocationKind::Room,
            'name' => [Locale::English->value => 'Room '.fake()->numberBetween(100, 999)],
            'is_active' => true,
            'position' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * A location belonging to an existing tenant.
     */
    public function ofTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->getKey(),
        ]);
    }

    /**
     * A room a guest is staying in.
     */
    public function room(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => LocationKind::Room,
            'name' => [Locale::English->value => 'Room '.fake()->numberBetween(100, 999)],
        ]);
    }

    /**
     * A table a guest is seated at.
     */
    public function table(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => LocationKind::Table,
            'name' => [Locale::English->value => 'Table '.fake()->numberBetween(1, 60)],
        ]);
    }

    /**
     * A delivery point that is neither a room nor a table.
     */
    public function area(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => LocationKind::Area,
            'name' => [Locale::English->value => fake()->randomElement(['Poolside', 'Entrance', 'Beach', 'Lobby', 'Garden'])],
        ]);
    }

    /**
     * A grouping rather than a destination: a floor, a terrace, a wing.
     *
     * Nothing is ever ordered to one (LocationKind::isDeliverable()), and it
     * is the only kind that may hold other locations under it.
     */
    public function zone(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => LocationKind::Zone,
            'name' => [Locale::English->value => fake()->randomElement(['Floor 1', 'Floor 2', 'Terrace', 'North wing'])],
        ]);
    }

    /**
     * Sitting under a zone, on that zone's own tenant.
     *
     * Two levels is the whole of the depth — LocationObserver refuses a third
     * — so the parent handed here must itself be top level.
     */
    public function withParent(Location $zone): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $zone->tenant_id,
            'parent_id' => $zone->getKey(),
        ]);
    }

    /**
     * Switched off: kept, and offered to no guest.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
