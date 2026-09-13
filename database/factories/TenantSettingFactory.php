<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantSetting>
 */
class TenantSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contact_email' => fake()->unique()->companyEmail(),
            'contact_phone' => fake()->numerify('+91 ##### #####'),
            'currency' => Currency::IndianRupee,
            'gstin' => null,
            // The tenant rate and prices quoted before tax, which is how a
            // tenant starts out.
            'tax_rate_basis_points' => TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS,
            'prices_include_tax' => false,
            'accepts_orders' => true,
            'opens_at' => '09:00:00',
            'closes_at' => '23:00:00',
        ];
    }

    /**
     * A tenant whose menu prices already have GST inside them.
     */
    public function pricesIncludingTax(): static
    {
        return $this->state(fn (array $attributes): array => [
            'prices_include_tax' => true,
        ]);
    }

    /**
     * Indicate that the tenant is not taking orders.
     */
    public function closedForOrders(): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepts_orders' => false,
        ]);
    }
}
