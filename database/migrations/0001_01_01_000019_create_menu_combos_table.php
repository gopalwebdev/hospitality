<?php

use App\Enums\ItemAvailability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->integer('original_price')->nullable();
            $table->smallInteger('tax_rate')->nullable();
            // As on an item: HSN for goods, SAC for a service.
            $table->string('hsn_sac_code', 8)->nullable();
            $table->string('availability', 32)->default(ItemAvailability::Available->value);
            // The most one order may hold, across every basket line it is on; null is no limit.
            $table->smallInteger('max_per_order')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('menu_combos');
    }
};
