<?php

namespace Database\Factories;

use App\Enums\CountryCallingCode;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
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
     * Every restaurant has settings, so one is made alongside it.
     *
     * The id is passed straight through rather than nesting a factory, which
     * would otherwise create a second restaurant to hang the settings off.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Restaurant $restaurant): void {
            RestaurantSetting::factory()->create([
                'tenant_id' => $restaurant->getKey(),
            ]);
        });
    }

    /**
     * Indicate that the restaurant is closed and should not serve its storefront.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
