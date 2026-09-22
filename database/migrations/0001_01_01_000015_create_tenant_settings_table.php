<?php

use App\Enums\Currency;
use App\Models\TenantSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            // A second mobile and the desk line, both of which a guest may be given.
            $table->string('alternate_phone', 32)->nullable();
            $table->string('landline_phone', 32)->nullable();
            $table->string('currency', 3)->default(Currency::IndianRupee->value);
            $table->string('gstin', 15)->nullable();
            // On: the state's half of a bill reads UTGST rather than SGST. It
            // rides the same columns and moves no money, and a tenant states it
            // on the Settings page — nothing works it out from where it is.
            $table->boolean('is_union_territory')->default(false);
            // GST is levied in halves: CGST to the centre and SGST to the state.
            // Both in basis points — 2.5% is 250 — and the rate anything is
            // taxed at is the two added up. This is the tenant's default: an
            // item with a tax_rate of its own is halved the same way.
            $table->smallInteger('cgst_rate')->default(intdiv(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS, 2));
            $table->smallInteger('sgst_rate')->default(intdiv(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS, 2));
            // On: this rate is what every item is taxed at, whatever its own says.
            $table->boolean('tax_overrides_item_rates')->default(false);
            $table->boolean('prices_include_tax')->default(false);
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
