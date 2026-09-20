<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_add_on_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_add_on_group_id')->constrained()->cascadeOnDelete();
            $table->jsonb('name');
            // What one of it adds to the item's price; zero is a real price. No tax
            // rate: an add-on is part of the item it is added to, taxed at its rate.
            $table->integer('price')->default(0);
            // How many of this one option a guest may take on one item: 2 × extra cheese.
            $table->smallInteger('max_quantity')->default(1);
            // Ticked for the guest when they open the item: "Medium" on a spice level.
            $table->boolean('is_default')->default(false);
            // The admin's own switch. An option with none left is hidden as well,
            // by MenuAddOnOption::scopeAvailable(), without turning this off.
            $table->boolean('is_available')->default(true);
            // How many are left, shared by every item offering the option; null is
            // not counted. Changed on an existing option only through ApplyStockChanges.
            $table->integer('stock_quantity')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE menu_add_on_options
            ADD CONSTRAINT menu_add_on_options_position_not_negative CHECK ("position" >= 0),
            ADD CONSTRAINT menu_add_on_options_price_not_negative CHECK (price >= 0),
            ADD CONSTRAINT menu_add_on_options_max_quantity_in_range CHECK (max_quantity BETWEEN 1 AND 99),
            ADD CONSTRAINT menu_add_on_options_stock_not_negative CHECK (stock_quantity IS NULL OR stock_quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_add_on_options');
    }
};
