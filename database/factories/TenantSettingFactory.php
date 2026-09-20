<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\GstTreatment;
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
            // Most tenants give one number; the other two are there for the ones that give more.
            'alternate_phone' => null,
            'landline_phone' => null,
            'currency' => Currency::IndianRupee,
            'gstin' => null,
            // How a tenant starts out: levied in halves, charging nothing until
            // it says what it charges, prices quoted before tax and an item's
            // own rate left to stand. A test that needs a rate says taxedAt().
            'gst_treatment' => GstTreatment::IntraState,
            'cgst_rate' => intdiv(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS, 2),
            'sgst_rate' => TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS - intdiv(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS, 2),
            'tax_overrides_item_rates' => false,
            'prices_include_tax' => false,
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
     * A tenant taxing everything at one rate, whatever an item's own says.
     */
    public function overridingItemTaxRates(): static
    {
        return $this->state(fn (array $attributes): array => [
            'tax_overrides_item_rates' => true,
        ]);
    }

    /**
     * Levied as one inter-state tax rather than in halves.
     */
    public function interState(): static
    {
        return $this->state(fn (array $attributes): array => [
            'gst_treatment' => GstTreatment::InterState,
        ]);
    }

    /**
     * Taxed at $basisPoints in all, split into the centre's half and the state's.
     */
    public function taxedAt(int $basisPoints): static
    {
        return $this->state(fn (array $attributes): array => [
            'cgst_rate' => intdiv($basisPoints, 2),
            'sgst_rate' => $basisPoints - intdiv($basisPoints, 2),
        ]);
    }
}
