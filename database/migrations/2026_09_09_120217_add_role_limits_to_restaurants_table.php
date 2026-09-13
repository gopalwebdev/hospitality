<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many accounts may hold the admin and staff roles at this restaurant.
     *
     * A restaurant needs exactly one admin for now, and a small, bounded
     * staff roster — see App\Actions\Restaurants\EnsureRoleFitsWithinLimit,
     * which is what these columns are read by. A super admin edits both from
     * the restaurant's own record; config/restaurants.php only supplies what
     * a newly created restaurant starts with.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_admins')
                ->default(config('tenants.default_max_admins'))
                ->after('is_active');

            $table->unsignedSmallInteger('max_staff')
                ->default(config('tenants.default_max_staff'))
                ->after('max_admins');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn(['max_admins', 'max_staff']);
        });
    }
};
