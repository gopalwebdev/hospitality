<?php

use App\Enums\Diet;
use App\Enums\ItemAvailability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_category_id')->constrained()->cascadeOnDelete();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            // Zero is a real price: a complimentary pillow, a free glass of water.
            $table->integer('price_minor_units');
            // The struck-through "was" price; null unless the item is on offer.
            $table->integer('compare_at_price_minor_units')->nullable();
            // Null falls back to tenant_settings.tax_rate_basis_points.
            $table->smallInteger('tax_rate_basis_points')->nullable();
            $table->string('hsn_code', 8)->nullable();
            // A service request — an extra pillow, a bedsheet change — rather than something to order.
            $table->boolean('is_service_request')->default(false);
            // Every mark the item carries: vegetarian and vegan together, or
            // one of the others on its own. Null exactly for a service
            // request; see MenuItemObserver.
            $table->jsonb('diets')->nullable();
            $table->string('availability', 32)->default(ItemAvailability::Available->value);
            // The most one order may hold, across every basket line it is on; null is no limit.
            $table->smallInteger('max_quantity')->nullable();
            // How many are left; null is not counted. Changed on an existing item only
            // through App\Actions\Inventory\ApplyStockChanges, which locks the row.
            $table->integer('stock_quantity')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->integer('featured_position')->default(0);
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE menu_items
            ADD CONSTRAINT menu_items_positions_not_negative CHECK ("position" >= 0 AND featured_position >= 0),
            ADD CONSTRAINT menu_items_prices_not_negative CHECK (price_minor_units >= 0 AND (compare_at_price_minor_units IS NULL OR compare_at_price_minor_units >= 0)),
            ADD CONSTRAINT menu_items_tax_rate_in_range CHECK (tax_rate_basis_points IS NULL OR tax_rate_basis_points BETWEEN 0 AND 10000),
            ADD CONSTRAINT menu_items_diets_match_service_request CHECK (is_service_request = (diets IS NULL)),
            ADD CONSTRAINT menu_items_max_quantity_in_range CHECK (max_quantity IS NULL OR max_quantity BETWEEN 1 AND 99)');

        // Which marks contradict each other comes from the enum, so a case
        // added later cannot leave the database accepting a combination the
        // form refuses. Each unordered pair is named once.
        $conflicts = [];

        foreach (Diet::cases() as $diet) {
            foreach (Diet::cases() as $other) {
                if ($diet->value < $other->value && ! $diet->goesWith($other)) {
                    $conflicts[] = "NOT (diets @> '[\"{$diet->value}\"]'::jsonb AND diets @> '[\"{$other->value}\"]'::jsonb)";
                }
            }
        }

        // An item that is not a service request carries at least one mark, and
        // never two that contradict each other.
        DB::statement('ALTER TABLE menu_items
            ADD CONSTRAINT menu_items_diets_are_consistent CHECK (
                diets IS NULL OR (jsonb_array_length(diets) >= 1 AND '.implode(' AND ', $conflicts).')
            )');

        $available = ItemAvailability::Available->value;

        // None left is never "available": MenuItemObserver marks it out of stock.
        DB::statement("ALTER TABLE menu_items
            ADD CONSTRAINT menu_items_stock_not_negative CHECK (stock_quantity IS NULL OR stock_quantity >= 0),
            ADD CONSTRAINT menu_items_none_left_is_not_available CHECK (stock_quantity IS NULL OR stock_quantity > 0 OR availability <> '{$available}')");
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
