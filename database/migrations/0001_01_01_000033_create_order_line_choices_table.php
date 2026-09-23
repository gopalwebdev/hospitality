<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->integer('price');
            // What this choice was actually invoiced under, copied at the time
            // because an order outlives the option. Both follow the item where
            // the option states neither, which is the usual case — the line's
            // own tax_rate is the principal item's.
            $table->smallInteger('tax_rate')->default(0);
            $table->string('hsn_sac_code', 8)->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('order_line_choices');
    }
};
