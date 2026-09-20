<?php

namespace Database\Factories;

use App\Enums\ChargeCalculation;
use App\Enums\Locale;
use App\Models\Charge;
use App\Models\Menu;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Charge>
 */
class ChargeFactory extends Factory
{
    /**
     * A 10% charge, switched on — the shape of a service charge. It is on no bill until onMenus() puts it on some.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => [Locale::English->value => ucfirst(fake()->unique()->word()).' Charge '.fake()->unique()->numberBetween(1, 9999)],
            'calculation' => ChargeCalculation::Percentage,
            'rate' => 1000,
            'amount' => null,
            'is_active' => true,
            'position' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * A share of the bill, in basis points: 1000 is 10%.
     */
    public function percentage(int $basisPoints = 1000): static
    {
        return $this->state(fn (array $attributes): array => [
            'calculation' => ChargeCalculation::Percentage,
            'rate' => $basisPoints,
            'amount' => null,
        ]);
    }

    /**
     * The same amount on every bill, in minor units: 2000 is ₹20.
     */
    public function fixedAmount(int $minorUnits = 2000): static
    {
        return $this->state(fn (array $attributes): array => [
            'calculation' => ChargeCalculation::FixedAmount,
            'rate' => null,
            'amount' => $minorUnits,
        ]);
    }

    /**
     * A charge belonging to an existing tenant.
     */
    public function ofTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->getKey(),
        ]);
    }

    /**
     * Added to bills from these menus, and on their tenant.
     */
    public function onMenus(Menu $menu, Menu ...$more): static
    {
        $menus = [$menu, ...$more];

        return $this
            ->state(fn (array $attributes): array => [
                'tenant_id' => $menu->tenant_id,
            ])
            ->afterCreating(fn (Charge $charge) => $charge->menus()->attach(
                array_map(static fn (Menu $each): int => $each->getKey(), $menus),
            ));
    }

    /**
     * Switched off: kept, and added to no bill.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
