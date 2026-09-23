<?php

use App\Enums\ChargeCalculation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->jsonb('name');
            $table->string('calculation', 32);
            // Exactly one of these is filled: the one ChargeCalculation::valueColumn() names.
            $table->smallInteger('rate')->nullable();
            $table->integer('amount')->nullable();
            // What this charge is taxed at, chosen from tax_codes exactly as an
            // item's is — see PricingFields::taxCodePicker(). Null is the
            // ordinary answer and means "the tenant's own rate", never an
            // item's: a charge is its own supply and tax_overrides_item_rates
            // (which only ever reaches MenuItem/MenuCombo::taxRate()) has no
            // say over it either way. See .ai/rules/tax-codes.md.
            $table->smallInteger('tax_rate')->nullable();
            $table->string('hsn_sac_code', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
