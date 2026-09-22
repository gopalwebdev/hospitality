<?php

use App\Enums\CountryCallingCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            // The slug is the tenant's subdomain: `t1` serves t1.hospitality.com.
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
            $table->smallInteger('max_owners')->default(config('tenants.default_max_owners'));
            $table->smallInteger('max_staff')->default(config('tenants.default_max_staff'));
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
