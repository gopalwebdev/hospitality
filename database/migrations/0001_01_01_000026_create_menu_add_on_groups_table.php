<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_add_on_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // What a guest reads above the options: "Choose your bread".
            $table->jsonb('name');
            // Whether a guest has to pick at least one option before the item goes in.
            $table->boolean('is_required')->default(false);
            // The most picks a guest may make here; null is no limit. An item
            // linking this group may set its own, tighter or looser
            // (menu_item_add_on_groups.max_picks).
            $table->smallInteger('max_picks')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE menu_add_on_groups
            ADD CONSTRAINT menu_add_on_groups_max_picks_in_range CHECK (max_picks IS NULL OR max_picks BETWEEN 1 AND 99)');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_add_on_groups');
    }
};
