<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The roster pivot, named for the tenant — see the tenants migration in
     * this set for why. `tenant_user` is also the name Laravel infers for a
     * belongsToMany between Tenant and User, so the relationships no longer
     * have to spell it out.
     */
    public function up(): void
    {
        Schema::rename('restaurant_user', 'tenant_user');
    }

    public function down(): void
    {
        Schema::rename('tenant_user', 'restaurant_user');
    }
};
