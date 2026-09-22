<?php

namespace Database\Factories;

use App\Models\TaxCode;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxCode>
 */
class TaxCodeFactory extends Factory
{
    protected $model = TaxCode::class;

    /**
     * A catalogue row by default — no tenant — because that is what most of them are.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'code' => (string) fake()->unique()->numberBetween(100000, 999999),
            'description' => fake()->words(3, true),
            'tax_rate' => 500,
        ];
    }

    /**
     * A row one tenant added for itself, which nobody else is offered.
     */
    public function ofTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->getKey(),
        ]);
    }

    /**
     * Carrying $basisPoints of GST: 1800 is 18%.
     */
    public function taxedAt(int $basisPoints): static
    {
        return $this->state(fn (array $attributes): array => [
            'tax_rate' => $basisPoints,
        ]);
    }
}
