<?php

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
            // Null exactly for a service request; see MenuItemObserver.
            $table->string('diet', 32)->nullable();
            $table->string('availability', 32)->default(ItemAvailability::Available->value);
            $table->boolean('is_featured')->default(false);
            $table->integer('featured_position')->default(0);
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE menu_items
            ADD CONSTRAINT menu_items_positions_not_negative CHECK ("position" >= 0 AND featured_position >= 0),
            ADD CONSTRAINT menu_items_prices_not_negative CHECK (price_minor_units >= 0 AND (compare_at_price_minor_units IS NULL OR compare_at_price_minor_units >= 0)),
            ADD CONSTRAINT menu_items_tax_rate_in_range CHECK (tax_rate_basis_points IS NULL OR tax_rate_basis_points BETWEEN 0 AND 10000),
            ADD CONSTRAINT menu_items_diet_matches_service_request CHECK (is_service_request = (diet IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
