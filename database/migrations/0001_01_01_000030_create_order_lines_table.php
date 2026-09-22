<?php

use App\Enums\OrderLineType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // App\Enums\OrderLineType: which of the two keys below the line may fill.
            $table->string('type', 32);
            // What was ordered, forgotten if it is deleted later; the name keeps what the guest saw.
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('menu_combo_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('name');
            $table->integer('quantity');
            // One of it with its choices, and the whole line, as priced when it was placed.
            $table->integer('unit_price');
            $table->integer('total');
            // The rate every part of the line was taxed at: the item's, add-ons included.
            $table->smallInteger('tax_rate');
            // What that rate was charged on. It is the line total with tax added
            // on top, and the line total less its tax when prices include it, so
            // an invoice never has to work out which it was looking at.
            $table->integer('taxable_value')->default(0);
            // The rate and the amount of each part, which Rule 46 makes an
            // invoice show separately. The rates add up to tax_rate
            // (order_lines_tax_rates_add_up); UTGST rides the SGST columns.
            $table->smallInteger('cgst_rate')->default(0);
            $table->integer('cgst')->default(0);
            $table->smallInteger('sgst_rate')->default(0);
            $table->integer('sgst')->default(0);
            // Copied from the item or combo, because an order outlives it and a
            // tax invoice names a code per line.
            $table->string('hsn_sac_code', 8)->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        $types = collect(OrderLineType::cases())
            ->map(fn (OrderLineType $type): string => "'{$type->value}'")
            ->implode(', ');

        $item = OrderLineType::Item->value;
        $combo = OrderLineType::Combo->value;

        DB::statement("ALTER TABLE order_lines
            ADD CONSTRAINT order_lines_type_is_known CHECK (type IN ({$types})),
            ADD CONSTRAINT order_lines_key_matches_type CHECK ((type = '{$item}' AND menu_combo_id IS NULL) OR (type = '{$combo}' AND menu_item_id IS NULL)),
            ADD CONSTRAINT order_lines_quantity_at_least_one CHECK (quantity >= 1),
            ADD CONSTRAINT order_lines_money_not_negative CHECK (unit_price >= 0 AND total >= 0 AND taxable_value >= 0),
            ADD CONSTRAINT order_lines_tax_rate_in_range CHECK (tax_rate BETWEEN 0 AND 10000),
            ADD CONSTRAINT order_lines_tax_rates_add_up CHECK (cgst_rate + sgst_rate = tax_rate),
            ADD CONSTRAINT order_lines_tax_parts_not_negative CHECK (cgst >= 0 AND sgst >= 0),
            ADD CONSTRAINT order_lines_position_not_negative CHECK (\"position\" >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
    }
};
