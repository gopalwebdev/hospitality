<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->integer('position')->default(0);
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('order_charges');
    }
};
