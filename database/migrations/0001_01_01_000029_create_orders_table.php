<?php

use App\Enums\GstTreatment;
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
            $table->integer('subtotal');
            // How this bill's GST was levied, settled once when it was placed:
            // App\Enums\GstTreatment. A rate changes and a tenant moves, so an
            // order keeps what it was actually charged under.
            $table->string('gst_treatment', 32)->default(GstTreatment::IntraState->value);
            // The tax in all, and the parts an invoice has to show separately.
            // The three always add up to tax (orders_tax_parts_add_up).
            $table->integer('tax');
            $table->integer('cgst')->default(0);
            // The state's half. UTGST rides this column: the money is the same
            // and only the invoice's wording differs (GstTreatment).
            $table->integer('sgst')->default(0);
            $table->integer('igst')->default(0);
            $table->integer('charges_total');
            $table->integer('total');
            $table->boolean('prices_include_tax');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        $statuses = collect(OrderStatus::cases())
            ->map(fn (OrderStatus $status): string => "'{$status->value}'")
            ->implode(', ');

        $cancelled = OrderStatus::Cancelled->value;

        $treatments = collect(GstTreatment::cases())
            ->map(fn (GstTreatment $treatment): string => "'{$treatment->value}'")
            ->implode(', ');

        DB::statement("ALTER TABLE orders
            ADD CONSTRAINT orders_status_is_known CHECK (status IN ({$statuses})),
            ADD CONSTRAINT orders_money_not_negative CHECK (subtotal >= 0 AND tax >= 0 AND charges_total >= 0 AND total >= 0),
            ADD CONSTRAINT orders_gst_treatment_is_known CHECK (gst_treatment IN ({$treatments})),
            ADD CONSTRAINT orders_tax_parts_not_negative CHECK (cgst >= 0 AND sgst >= 0 AND igst >= 0),
            ADD CONSTRAINT orders_tax_parts_add_up CHECK (cgst + sgst + igst = tax),
            ADD CONSTRAINT orders_cancelled_at_matches_status CHECK ((status = '{$cancelled}') = (cancelled_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
