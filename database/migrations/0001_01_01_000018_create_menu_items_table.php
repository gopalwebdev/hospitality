<?php

use App\Enums\Diet;
use App\Enums\ItemAvailability;
use App\Enums\MenuItemKind;
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
            $table->integer('price');
            // The struck-through "was" price; null unless the item is on offer.
            $table->integer('original_price')->nullable();
            // Null falls back to tenant_settings.tax_rate.
            $table->smallInteger('tax_rate')->nullable();
            // HSN for a Consumable and Goods, SAC for a Service — App\Enums\MenuItemKind::taxCodeLabel()
            // says which. One column, because an invoice and GSTR-1 have one field.
            $table->string('hsn_sac_code', 8)->nullable();
            // Consumable, Goods or Service — App\Enums\MenuItemKind. The split is what
            // decides whether a diet mark applies and HSN against SAC on the invoice.
            $table->string('kind', 32)->default(MenuItemKind::Consumable->value);
            // Every mark the item carries: vegetarian and vegan together, or
            // one of the others on its own. Null exactly for a kind that does
            // not require one; see MenuItemObserver.
            $table->jsonb('diets')->nullable();
            $table->string('availability', 32)->default(ItemAvailability::Available->value);
            // The most one order may hold, across every basket line it is on; null is no limit.
            $table->smallInteger('max_per_order')->nullable();
            // How many are left; null is not counted. Changed on an existing item only
            // through App\Actions\Inventory\ApplyStockChanges, which locks the row.
            $table->integer('stock_quantity')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->integer('featured_position')->default(0);
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        // Which kinds carry a diet mark comes from the enum, so a case added
        // later cannot leave the database accepting a kind the form refuses a
        // diet on, or requiring one the form never asks for.
        $kinds = collect(MenuItemKind::cases())
            ->map(fn (MenuItemKind $kind): string => "'{$kind->value}'")
            ->implode(', ');

        $dietRequiringKinds = collect(MenuItemKind::cases())
            ->filter(fn (MenuItemKind $kind): bool => $kind->requiresDietMark())
            ->map(fn (MenuItemKind $kind): string => "'{$kind->value}'")
            ->implode(', ');

        DB::statement("ALTER TABLE menu_items
            ADD CONSTRAINT menu_items_positions_not_negative CHECK (\"position\" >= 0 AND featured_position >= 0),
            ADD CONSTRAINT menu_items_prices_not_negative CHECK (price >= 0 AND (original_price IS NULL OR original_price >= 0)),
            ADD CONSTRAINT menu_items_tax_rate_in_range CHECK (tax_rate IS NULL OR tax_rate BETWEEN 0 AND 10000),
            ADD CONSTRAINT menu_items_kind_is_known CHECK (kind IN ({$kinds})),
            ADD CONSTRAINT menu_items_diets_match_kind CHECK ((kind IN ({$dietRequiringKinds})) = (diets IS NOT NULL)),
            ADD CONSTRAINT menu_items_max_per_order_in_range CHECK (max_per_order IS NULL OR max_per_order BETWEEN 1 AND 99)");

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

        // An item whose kind requires a diet mark carries at least one, and
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
