<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the product team owner and the tenants that exist so far.
     *
     * Every seeder here is idempotent, so this is safe to re-run. Run
     * `php artisan accounts:list` afterwards to see who can now sign in.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminSeeder::class,
            TenantSeeder::class,
        ]);
    }
}
