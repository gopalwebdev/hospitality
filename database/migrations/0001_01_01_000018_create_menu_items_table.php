<?php

use App\Enums\Diet;
use App\Enums\ItemAvailability;
use App\Enums\MenuItemKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
