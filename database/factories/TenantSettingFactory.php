<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantSetting>
 */
class RestaurantSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Restaurant::factory(),
            'contact_email' => fake()->unique()->companyEmail(),
            'contact_phone' => fake()->numerify('+91 ##### #####'),
            'currency' => Currency::IndianRupee,
            'gstin' => null,
            // The restaurant rate, prices quoted before tax, and neither
            // charge levied — which is how a restaurant starts out.
            'tax_rate_basis_points' => RestaurantSetting::DEFAULT_TAX_RATE_BASIS_POINTS,
            'prices_include_tax' => false,
            'service_charge_enabled' => false,
            'service_charge_basis_points' => 0,
            'parcel_charge_enabled' => false,
            'parcel_charge_minor_units' => 0,
            'accepts_orders' => true,
            'opens_at' => '09:00:00',
            'closes_at' => '23:00:00',
        ];
    }

    /**
     * A restaurant whose menu prices already have GST inside them.
     */
    public function pricesIncludingTax(): static
    {
        return $this->state(fn (array $attributes): array => [
            'prices_include_tax' => true,
        ]);
    }

    /**
     * A restaurant levying a service charge, as a percentage in basis points.
     */
    public function withServiceCharge(int $basisPoints = 1000): static
    {
        return $this->state(fn (array $attributes): array => [
            'service_charge_enabled' => true,
            'service_charge_basis_points' => $basisPoints,
        ]);
    }

    /**
     * A restaurant charging a flat amount to pack an order to take away.
     */
    public function withParcelCharge(int $minorUnits = 2000): static
    {
        return $this->state(fn (array $attributes): array => [
            'parcel_charge_enabled' => true,
            'parcel_charge_minor_units' => $minorUnits,
        ]);
    }

    /**
     * Indicate that the restaurant is not taking orders.
     */
    public function closedForOrders(): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepts_orders' => false,
        ]);
    }
}
