<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // App\Enums\LocationKind: a room, a table, a delivery point like a pool or an
            // entrance, or a zone grouping other locations ("Floor 2").
            $table->string('kind', 32);
            // A zone this location sits under; null is top level. Two levels, no more —
            // LocationObserver is the only guard, every CHECK constraint having been dropped.
            $table->foreignId('parent_id')->nullable()->constrained('locations')->cascadeOnDelete();
            // Guest-facing: "Room 204", "Table 5", "Poolside". Copied onto orders.location_name
            // when picked, so renaming or deleting a location never rewrites an order.
            $table->jsonb('name');
            // Staff shorthand and the QR deep-link to come: "204", "T5".
            $table->string('code', 16)->nullable();
            // Beds in a room, seats at a table.
            $table->smallInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
