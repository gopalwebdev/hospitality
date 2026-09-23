<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Whose count changed: exactly one of the two. Two real keys rather than
            // a polymorphic pair, because every relationship here is a foreign key.
            $table->foreignId('menu_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('menu_add_on_option_id')->nullable()->constrained()->cascadeOnDelete();
            // App\Enums\StockMovementReason.
            $table->string('reason', 32);
            // Signed: +10 arrived, -3 went out with an order.
            $table->integer('quantity_change');
            $table->integer('quantity_after');
            // The order that took it or had it put back.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // Who changed it from the panel; null for a guest's order.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 120)->nullable();
            // A movement is never edited, so there is no updated_at.
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
