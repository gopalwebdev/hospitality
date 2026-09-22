<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table): void {
            $table->id();
            // Null is the catalogue the product team seeds, which every tenant
            // is offered. A row naming a tenant is that tenant's own addition,
            // and nobody else sees it.
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            // HSN numbers goods and SAC numbers services. One column for both,
            // because an invoice and GSTR-1 have one — see PricingFields.
            $table->string('code', 8);
            // What the code covers, in the words someone would search for.
            $table->string('description');
            // Basis points, like every rate here: 5% is 500. It is a starting
            // value the item form copies, never a rate anything is taxed at —
            // menu_items.tax_rate is what a bill reads.
            $table->smallInteger('tax_rate');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE tax_codes
            ADD CONSTRAINT tax_codes_tax_rate_in_range CHECK (tax_rate BETWEEN 0 AND 10000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_codes');
    }
};
