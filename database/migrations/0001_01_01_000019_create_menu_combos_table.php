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
            $table->integer('price_minor_units');
            $table->integer('compare_at_price_minor_units')->nullable();
            $table->smallInteger('tax_rate_basis_points')->nullable();
            $table->string('availability', 32)->default(ItemAvailability::Available->value);
            // How many one order may hold, across every basket line it is on; a null maximum is no limit.
            $table->smallInteger('min_quantity')->default(1);
            $table->smallInteger('max_quantity')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE menu_combos
            ADD CONSTRAINT menu_combos_position_not_negative CHECK ("position" >= 0),
            ADD CONSTRAINT menu_combos_prices_not_negative CHECK (price_minor_units >= 0 AND (compare_at_price_minor_units IS NULL OR compare_at_price_minor_units >= 0)),
            ADD CONSTRAINT menu_combos_tax_rate_in_range CHECK (tax_rate_basis_points IS NULL OR tax_rate_basis_points BETWEEN 0 AND 10000),
            ADD CONSTRAINT menu_combos_quantities_in_range CHECK (min_quantity BETWEEN 1 AND 99 AND (max_quantity IS NULL OR max_quantity BETWEEN min_quantity AND 99))');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_combos');
    }
};
