<?php

use App\Enums\CountryCallingCode;
use App\Enums\TenantType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            // The slug is the tenant's subdomain: `t1` serves t1.tenant-app.com.
            $table->string('slug');
            $table->string('name');
            $table->string('type', 32);
            $table->string('address');
            $table->string('pincode', 16);
            $table->string('email')->nullable();
            $table->string('phone_country_code', 4)->default(CountryCallingCode::India->value);
            $table->string('phone', CountryCallingCode::longestMobileNumberLength());
            $table->string('secondary_phone_country_code', 4)->nullable();
            $table->string('secondary_phone', CountryCallingCode::longestMobileNumberLength())->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('max_admins')->default(config('tenants.default_max_admins'));
            $table->smallInteger('max_staff')->default(config('tenants.default_max_staff'));
            $table->timestamps();
        });

        $types = collect(TenantType::cases())
            ->map(fn (TenantType $type): string => "'{$type->value}'")
            ->implode(', ');

        DB::statement("ALTER TABLE tenants
            ADD CONSTRAINT tenants_role_limits_not_negative CHECK (max_admins >= 0 AND max_staff >= 0),
            ADD CONSTRAINT tenants_type_is_known CHECK (type IN ({$types}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
