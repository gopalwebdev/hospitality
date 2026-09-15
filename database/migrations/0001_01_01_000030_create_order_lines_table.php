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
            $table->integer('unit_price_minor_units');
            $table->integer('total_minor_units');
            // The rate every part of the line was taxed at: the item's, add-ons included.
            $table->smallInteger('tax_rate_basis_points');
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
            ADD CONSTRAINT order_lines_money_not_negative CHECK (unit_price_minor_units >= 0 AND total_minor_units >= 0),
            ADD CONSTRAINT order_lines_tax_rate_in_range CHECK (tax_rate_basis_points BETWEEN 0 AND 10000),
            ADD CONSTRAINT order_lines_position_not_negative CHECK (\"position\" >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
    }
};
