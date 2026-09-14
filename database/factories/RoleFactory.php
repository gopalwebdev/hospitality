<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    #[\Override]
    protected $model = Role::class;

    /**
     * A custom role: one with no App\Enums\Role case behind it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'custom-'.fake()->unique()->word(),
            'guard_name' => config('auth.defaults.guard'),
        ];
    }
}
