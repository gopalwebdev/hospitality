<?php

use App\Enums\Currency;
use App\Models\TenantSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('currency', 3)->default(Currency::IndianRupee->value);
            $table->string('gstin', 15)->nullable();
            // Basis points: 5% is 500. What is added on top of a bill lives in charges.
            $table->smallInteger('tax_rate_basis_points')->default(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS);
            $table->boolean('prices_include_tax')->default(false);
            $table->boolean('accepts_orders')->default(true);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE tenant_settings
            ADD CONSTRAINT tenant_settings_tax_rate_in_range CHECK (tax_rate_basis_points BETWEEN 0 AND 10000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
