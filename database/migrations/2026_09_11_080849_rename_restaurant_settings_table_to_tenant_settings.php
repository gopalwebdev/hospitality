<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tenant's settings, named for the tenant — see the tenants migration in
     * this set for why, and for which names follow the table.
     *
     * The three CHECK constraints are renamed with it, because their names are
     * written out as literals in `add_checks_to_restaurant_settings_table`.
     */
    private const array CONSTRAINTS = [
        'tax_rate_in_range',
        'service_charge_in_range',
        'parcel_charge_not_negative',
    ];

    public function up(): void
    {
        Schema::rename('restaurant_settings', 'tenant_settings');

        foreach (self::CONSTRAINTS as $constraint) {
            DB::statement("ALTER TABLE tenant_settings RENAME CONSTRAINT restaurant_settings_{$constraint} TO tenant_settings_{$constraint}");
        }
    }

    public function down(): void
    {
        foreach (self::CONSTRAINTS as $constraint) {
            DB::statement("ALTER TABLE tenant_settings RENAME CONSTRAINT tenant_settings_{$constraint} TO restaurant_settings_{$constraint}");
        }

        Schema::rename('tenant_settings', 'restaurant_settings');
    }
};
