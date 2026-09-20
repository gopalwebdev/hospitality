<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // The charge it came from, forgotten if that charge is deleted later.
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('name');
            // What it added to this bill, worked out when the order was placed.
            $table->integer('amount');
            // A charge is part of the value of the supply and is taxed with it,
            // at the tenant's own rate. Shaped exactly as a line's tax is, so an
            // invoice prints a charge and an item the same way.
            $table->smallInteger('tax_rate')->default(0);
            $table->integer('taxable_value')->default(0);
            $table->smallInteger('cgst_rate')->default(0);
            $table->integer('cgst')->default(0);
            $table->smallInteger('sgst_rate')->default(0);
            $table->integer('sgst')->default(0);
            $table->smallInteger('igst_rate')->default(0);
            $table->integer('igst')->default(0);
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE order_charges
            ADD CONSTRAINT order_charges_amount_not_negative CHECK (amount >= 0 AND taxable_value >= 0),
            ADD CONSTRAINT order_charges_tax_rate_in_range CHECK (tax_rate BETWEEN 0 AND 10000),
            ADD CONSTRAINT order_charges_tax_rates_add_up CHECK (cgst_rate + sgst_rate + igst_rate = tax_rate),
            ADD CONSTRAINT order_charges_tax_parts_not_negative CHECK (cgst >= 0 AND sgst >= 0 AND igst >= 0),
            ADD CONSTRAINT order_charges_position_not_negative CHECK ("position" >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_charges');
    }
};
