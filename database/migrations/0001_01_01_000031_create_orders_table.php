<?php

use App\Enums\OrderSettlement;
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
            // Where it's going, when the guest picked one of this tenant's listed
            // locations rather than typing free text. An order outlives a deleted
            // location; location_name below keeps the name it read.
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            // App\Enums\OrderStatus.
            $table->string('status', 32)->default(OrderStatus::Placed->value);
            // Where to bring it: a copy of the picked location's name in every
            // language, or the guest's own free text under the default locale when
            // none was picked. An order is a copy, so deleting or renaming a
            // location never rewrites an order already delivered there.
            $table->jsonb('location_name')->nullable();
            // The guest's intent to pay now or add it to the bill — App\Enums\OrderSettlement.
            // Not the truth about the money: payments and order_payments are that.
            $table->string('settlement', 32)->default(OrderSettlement::AddToBill->value);
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
