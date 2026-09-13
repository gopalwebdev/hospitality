<?php

namespace Database\Factories;

use App\Enums\FoodType;
use App\Enums\ItemAvailability;
use App\Enums\Locale;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // The category brings the tenant with it. Letting the two be
        // chosen independently is exactly what MenuItemObserver refuses.
        $category = MenuCategory::factory();
        $english = Locale::English->value;

        return [
            'menu_category_id' => $category,
            'tenant_id' => fn (array $attributes): int => MenuCategory::query()
                ->whereKey($attributes['menu_category_id'])
                ->value('tenant_id'),
            'name' => [$english => ucfirst(fake()->unique()->word()).' '.fake()->unique()->numberBetween(1, 9999)],
            'description' => [$english => fake()->sentence()],
            'price_minor_units' => fake()->numberBetween(5000, 90000),
            // Most dishes carry neither: no offer, and the tenant's own
            // GST slab. Both are set by a state when a test is about them.
            'compare_at_price_minor_units' => null,
            'tax_rate_basis_points' => null,
            'hsn_code' => null,
            'food_type' => fake()->randomElement(FoodType::cases()),
            'availability' => ItemAvailability::Available,
            'position' => fake()->numberBetween(0, 20),
        ];
    }

    /**
     * Put this dish under an existing category, at either level, and its
     * tenant with it.
     */
    public function inCategory(MenuCategory $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'menu_category_id' => $category->getKey(),
            'tenant_id' => $category->tenant_id,
        ]);
    }

    /**
     * A dish advertised with a higher price struck through beside it.
     */
    public function discounted(?int $compareAtMinorUnits = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'compare_at_price_minor_units' => $compareAtMinorUnits ?? $attributes['price_minor_units'] + 5000,
        ]);
    }

    /**
     * A dish taxed at a rate of its own rather than the tenant's default.
     *
     * Basis points, as stored: 1800 is 18%.
     */
    public function taxedAt(int $basisPoints): static
    {
        return $this->state(fn (array $attributes): array => [
            'tax_rate_basis_points' => $basisPoints,
        ]);
    }

    /**
     * A dish with every language filled in.
     */
    public function translated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => [
                Locale::English->value => $attributes['name'][Locale::English->value],
                Locale::Tamil->value => 'உணவு '.fake()->unique()->numberBetween(1, 9999),
            ],
            'description' => [
                Locale::English->value => $attributes['description'][Locale::English->value],
                Locale::Tamil->value => 'சுவையான உணவு.',
            ],
        ]);
    }

    /**
     * A dish with no description, which is allowed and common.
     */
    public function withoutDescription(): static
    {
        return $this->state(fn (array $attributes): array => [
            'description' => null,
        ]);
    }

    /**
     * Off the menu for now, and saying why.
     *
     * Defaults to sold out, which is the common reason; pass
     * ItemAvailability::TemporarilyUnavailable for the other one.
     */
    public function unavailable(ItemAvailability $reason = ItemAvailability::OutOfStock): static
    {
        return $this->state(fn (array $attributes): array => [
            'availability' => $reason,
        ]);
    }
}
