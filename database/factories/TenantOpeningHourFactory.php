<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\Tenant;
use App\Models\TenantOpeningHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One day of a tenant's week, open between two times unless it is the holiday.
 *
 * @extends Factory<TenantOpeningHour>
 */
class TenantOpeningHourFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'weekday' => Weekday::Monday,
            'is_closed' => false,
            'opens_at' => '09:00:00',
            'closes_at' => '23:00:00',
        ];
    }

    /**
     * The day of the week this row keeps.
     */
    public function on(Weekday $weekday): static
    {
        return $this->state(fn (array $attributes): array => [
            'weekday' => $weekday,
        ]);
    }

    /**
     * Open between two times, each as the clock picker sets them.
     */
    public function between(string $opensAt, string $closesAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_closed' => false,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
        ]);
    }

    /**
     * The weekly holiday: shut all day, with no hours to read.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_closed' => true,
            'opens_at' => null,
            'closes_at' => null,
        ]);
    }

    /**
     * Belonging to a tenant that already exists.
     */
    public function ofTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->getKey(),
        ]);
    }
}
