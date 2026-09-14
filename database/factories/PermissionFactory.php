<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    #[\Override]
    protected $model = Permission::class;

    /**
     * A custom permission: one with no App\Enums\Permission case behind it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'custom.'.fake()->unique()->word(),
            'guard_name' => config('auth.defaults.guard'),
        ];
    }
}
