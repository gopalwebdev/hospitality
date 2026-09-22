<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            // What one of it adds to the item's price; zero is a real price.
            $table->integer('price')->default(0);
            // An add-on is normally part of the item it is added to — a
            // composite supply taxed at its rate (CGST Act, s. 8(a)) — so this
            // is null on almost every option. It is here for the one that is
            // not: a tenant selling a haircut beside a meal files the two under
            // different codes and different slabs. Basis points; null follows
            // the item. See .ai/rules/actions-menus.md.
            $table->smallInteger('tax_rate')->nullable();
            $table->string('hsn_sac_code', 8)->nullable();
            // How many of this one option a guest may take on one item: 2 × extra cheese.
            $table->smallInteger('max_per_item')->default(1);
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

    }

    public function down(): void
    {
        Schema::dropIfExists('menu_add_on_options');
    }
};
