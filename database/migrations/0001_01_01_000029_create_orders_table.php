<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            // What the basket came to when it was placed, as QuoteBasket priced it. Never worked out again.
            $table->integer('subtotal_minor_units');
            $table->integer('tax_minor_units');
            $table->integer('charges_minor_units');
            $table->integer('total_minor_units');
            $table->boolean('prices_include_tax');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        $statuses = collect(OrderStatus::cases())
            ->map(fn (OrderStatus $status): string => "'{$status->value}'")
            ->implode(', ');

        $cancelled = OrderStatus::Cancelled->value;

        DB::statement("ALTER TABLE orders
            ADD CONSTRAINT orders_status_is_known CHECK (status IN ({$statuses})),
            ADD CONSTRAINT orders_money_not_negative CHECK (subtotal_minor_units >= 0 AND tax_minor_units >= 0 AND charges_minor_units >= 0 AND total_minor_units >= 0),
            ADD CONSTRAINT orders_cancelled_at_matches_status CHECK ((status = '{$cancelled}') = (cancelled_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
