<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_line_choices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained()->cascadeOnDelete();
            // The option picked, forgotten if it is deleted later; the name keeps what the guest saw.
            $table->foreignId('menu_add_on_option_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('name');
            // How many on one of the line's item: 2 × extra cheese.
            $table->integer('quantity');
            // What one of it added, when the order was placed.
            $table->integer('price_minor_units');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE order_line_choices
            ADD CONSTRAINT order_line_choices_quantity_at_least_one CHECK (quantity >= 1),
            ADD CONSTRAINT order_line_choices_price_not_negative CHECK (price_minor_units >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_line_choices');
    }
};
