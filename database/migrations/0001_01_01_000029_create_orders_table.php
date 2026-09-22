<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The menu it was ordered from. An order outlives a deleted menu; its lines keep the names.
            $table->foreignId('menu_id')->nullable()->constrained()->nullOnDelete();
            // App\Enums\OrderStatus.
            $table->string('status', 32)->default(OrderStatus::Placed->value);
            // Where to bring it, as the guest typed it: "Room 204", "Table 5". Guests have no account.
            $table->string('location_label', 40)->nullable();
            $table->string('note', 200)->nullable();
            // What the basket came to when it was placed, as PriceBasket priced it. Never worked out again.
            $table->integer('subtotal');
            // What this bill called the state's half, settled once when it was
            // placed: a tenant can move, so an order keeps its own wording.
            $table->boolean('is_union_territory')->default(false);
            // The tax in all, and the parts an invoice has to show separately.
            // The two always add up to tax (orders_tax_parts_add_up).
            $table->integer('tax');
            $table->integer('cgst')->default(0);
            // The state's half. UTGST rides this column: the money is the same
            // and only the invoice's wording differs (is_union_territory).
            $table->integer('sgst')->default(0);
            $table->integer('charges_total');
            $table->integer('total');
            $table->boolean('prices_include_tax');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
