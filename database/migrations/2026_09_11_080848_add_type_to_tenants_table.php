<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What kind of business a tenant is: a hotel or a restaurant.
     *
     * Cast to App\Enums\TenantType. Every tenant that existed before this was a
     * restaurant, so the column arrives with that as its default to fill those
     * rows, and the default is then dropped: a tenant created from here on has
     * to say what it is.
     *
     * The values are written out rather than read from the enum, so this
     * migration means the same thing when it is run again after a case is
     * added. A new type is a new migration replacing the constraint.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('type', 32)->default('restaurant');
        });

        DB::statement('ALTER TABLE tenants ALTER COLUMN type DROP DEFAULT');
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_type_is_known CHECK (type IN ('hotel', 'restaurant'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_type_is_known');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
