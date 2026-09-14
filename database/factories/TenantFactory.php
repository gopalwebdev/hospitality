<?php

namespace Database\Factories;

use App\Enums\CountryCallingCode;
use App\Enums\TenantType;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    #[\Override]
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'type' => fake()->randomElement(TenantType::cases()),
            'address' => fake()->streetAddress().', '.fake()->city(),
            'pincode' => (string) fake()->numberBetween(100000, 999999),
            'email' => fake()->unique()->companyEmail(),
            'phone_country_code' => CountryCallingCode::India,
            'phone' => fake()->numerify('9#########'),
            'secondary_phone_country_code' => null,
            'secondary_phone' => null,
            'is_active' => true,
        ];
    }

    /**
     * Every tenant has settings, so one is made alongside it.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            TenantSetting::factory()->create([
                'tenant_id' => $tenant->getKey(),
            ]);
        });
    }

    /**
     * A tenant that is closed and does not serve its storefront.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
