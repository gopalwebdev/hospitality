<?php

use App\Enums\ItemAvailability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_combos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            // Its own price, never the sum of its items.
            $table->integer('price');
            $table->integer('compare_at_price')->nullable();
            $table->smallInteger('tax_rate')->nullable();
            // As on an item: HSN for goods, SAC for a service.
            $table->string('hsn_sac_code', 8)->nullable();
            $table->string('availability', 32)->default(ItemAvailability::Available->value);
            // The most one order may hold, across every basket line it is on; null is no limit.
            $table->smallInteger('max_quantity')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE menu_combos
            ADD CONSTRAINT menu_combos_position_not_negative CHECK ("position" >= 0),
            ADD CONSTRAINT menu_combos_prices_not_negative CHECK (price >= 0 AND (compare_at_price IS NULL OR compare_at_price >= 0)),
            ADD CONSTRAINT menu_combos_tax_rate_in_range CHECK (tax_rate IS NULL OR tax_rate BETWEEN 0 AND 10000),
            ADD CONSTRAINT menu_combos_max_quantity_in_range CHECK (max_quantity IS NULL OR max_quantity BETWEEN 1 AND 99)');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_combos');
    }
};
