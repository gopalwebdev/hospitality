<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the product team owner, the tenants that exist so far, and a
     * spread of orders and stock history against them.
     *
     * Order matters: roles and the tenants' menus have to exist before
     * OrderSeeder can place anything against them, and every seeder here is
     * idempotent, so this is safe to re-run. Run `php artisan accounts:list`
     * afterwards to see who can now sign in.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminSeeder::class,
            // Before the tenants, so a seeded menu could reference a code and
            // so the first tenant to open the panel already has the catalogue.
            TaxCodeSeeder::class,
            TenantSeeder::class,
            OrderSeeder::class,
        ]);
    }
}
