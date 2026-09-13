<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_additions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->jsonb('name');
            // What the addition adds to the dish; zero is a real price.
            $table->integer('price_minor_units')->default(0);
            $table->smallInteger('tax_rate_basis_points')->nullable();
            $table->boolean('is_available')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE menu_item_additions
            ADD CONSTRAINT menu_item_additions_position_not_negative CHECK ("position" >= 0),
            ADD CONSTRAINT menu_item_additions_price_not_negative CHECK (price_minor_units >= 0),
            ADD CONSTRAINT menu_item_additions_tax_rate_in_range CHECK (tax_rate_basis_points IS NULL OR tax_rate_basis_points BETWEEN 0 AND 10000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_additions');
    }
};
