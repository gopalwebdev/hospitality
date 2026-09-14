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
            $table->integer('price_minor_units')->default(0);
            // How many of this one option a guest may take: 2 × extra cheese. Only
            // read while its group allows quantities.
            $table->smallInteger('max_quantity')->default(1);
            // Ticked for the guest when they open the item: "Medium" on a spice level.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_available')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE menu_add_on_options
            ADD CONSTRAINT menu_add_on_options_position_not_negative CHECK ("position" >= 0),
            ADD CONSTRAINT menu_add_on_options_price_not_negative CHECK (price_minor_units >= 0),
            ADD CONSTRAINT menu_add_on_options_max_quantity_in_range CHECK (max_quantity BETWEEN 1 AND 99)');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_add_on_options');
    }
};
