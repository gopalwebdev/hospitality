<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_add_on_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_add_on_group_id')->constrained()->cascadeOnDelete();
            // Where the group sits among this item's groups; the same group may sit elsewhere on another item.
            $table->integer('position')->default(0);
            // This item's own cap on the group's picks, tighter or looser than
            // the group's own max_selections; null follows the group.
            $table->smallInteger('max_selections')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE menu_item_add_on_groups
            ADD CONSTRAINT menu_item_add_on_groups_position_not_negative CHECK ("position" >= 0),
            ADD CONSTRAINT menu_item_add_on_groups_max_in_range CHECK (max_selections IS NULL OR max_selections BETWEEN 1 AND 99)');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_add_on_groups');
    }
};
