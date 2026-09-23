<?php

namespace Database\Factories;

use App\Enums\PaymentDeviceKind;
use App\Models\PaymentDevice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A card machine or a QR code a tenant records payments against.
 *
 * @extends Factory<PaymentDevice>
 */
class PaymentDeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'kind' => PaymentDeviceKind::CardMachine,
            'name' => 'Counter machine '.fake()->numberBetween(1, 20),
            'identifier' => null,
            'is_active' => true,
            'position' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * A device belonging to an existing tenant.
     */
    public function ofTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->getKey(),
        ]);
    }

    /**
     * A card machine, for a credit or debit card payment.
     */
    public function cardMachine(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => PaymentDeviceKind::CardMachine,
            'name' => 'Counter machine '.fake()->numberBetween(1, 20),
        ]);
    }

    /**
     * A QR code, for a UPI payment.
     */
    public function qrCode(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => PaymentDeviceKind::QrCode,
            'name' => 'Reception QR '.fake()->numberBetween(1, 20),
        ]);
    }
}
