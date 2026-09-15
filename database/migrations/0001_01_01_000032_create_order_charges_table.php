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
            $table->integer('amount_minor_units');
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE order_charges
            ADD CONSTRAINT order_charges_amount_not_negative CHECK (amount_minor_units >= 0),
            ADD CONSTRAINT order_charges_position_not_negative CHECK ("position" >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_charges');
    }
};
