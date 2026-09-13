<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tenant boundary is a tenant, not a restaurant.
     *
     * The platform serves hotels as well as restaurants now, so the table that
     * every `tenant_id` points at is named for the boundary rather than for one
     * kind of business. This is the first of three table renames, one per
     * table, followed by the permission that named it.
     *
     * Postgres follows a table rename with every foreign key that references
     * it, so the composite keys that isolate one tenant from another survive
     * untouched. Generated names — the primary key, the slug unique, the
     * sequence — keep saying `restaurants`, which nothing queries by. The CHECK
     * constraint is different: its name is written out as a literal in
     * `add_checks_to_restaurants_table`, and the next migration that replaces
     * it will write it out again, so it is renamed with the table.
     */
    public function up(): void
    {
        Schema::rename('restaurants', 'tenants');

        DB::statement('ALTER TABLE tenants RENAME CONSTRAINT restaurants_role_limits_not_negative TO tenants_role_limits_not_negative');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants RENAME CONSTRAINT tenants_role_limits_not_negative TO restaurants_role_limits_not_negative');

        Schema::rename('tenants', 'restaurants');
    }
};
