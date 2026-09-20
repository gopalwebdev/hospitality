<?php

use App\Enums\Currency;
use App\Enums\GstTreatment;
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
            // A second mobile and the desk line, both of which a guest may be given.
            $table->string('alternate_phone', 32)->nullable();
            $table->string('landline_phone', 32)->nullable();
            $table->string('currency', 3)->default(Currency::IndianRupee->value);
            $table->string('gstin', 15)->nullable();
            // How this tenant's GST is levied, as the tenant states it on the
            // Settings page: App\Enums\GstTreatment. Nothing here works it out
            // from where the tenant is — a tenant says what it charges.
            $table->string('gst_treatment', 32)->default(GstTreatment::IntraState->value);
            // GST is levied in halves on an intra-state supply: CGST to the centre
            // and SGST to the state. Both in basis points — 2.5% is 250 — and the
            // rate anything is taxed at is the two added up.
            $table->smallInteger('cgst_rate')->default(intdiv(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS, 2));
            $table->smallInteger('sgst_rate')->default(intdiv(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS, 2));
            // On: this rate is what every item is taxed at, whatever its own says.
            $table->boolean('tax_overrides_item_rates')->default(false);
            $table->boolean('prices_include_tax')->default(false);
            $table->timestamps();
        });

        $treatments = collect(GstTreatment::cases())
            ->map(fn (GstTreatment $treatment): string => "'{$treatment->value}'")
            ->implode(', ');

        DB::statement("ALTER TABLE tenant_settings
            ADD CONSTRAINT tenant_settings_tax_rates_in_range CHECK (
                cgst_rate BETWEEN 0 AND 10000
                AND sgst_rate BETWEEN 0 AND 10000
                AND cgst_rate + sgst_rate <= 10000
            ),
            ADD CONSTRAINT tenant_settings_gst_treatment_is_known CHECK (gst_treatment IN ({$treatments}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
