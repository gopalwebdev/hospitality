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
            // The fewest picks a guest makes; zero is optional, one or more is required.
            $table->smallInteger('min_selections')->default(0);
            // The most picks a guest may make; null is no limit.
            $table->smallInteger('max_selections')->nullable();
            // Whether a guest may take one option more than once ("Extra cheese × 2").
            // Square calls it allow_quantities; only a group of more than one can.
            $table->boolean('allows_quantities')->default(false);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE menu_add_on_groups
            ADD CONSTRAINT menu_add_on_groups_min_not_negative CHECK (min_selections >= 0),
            ADD CONSTRAINT menu_add_on_groups_max_covers_min CHECK (max_selections IS NULL OR max_selections >= GREATEST(min_selections, 1)),
            ADD CONSTRAINT menu_add_on_groups_quantities_need_more_than_one CHECK (NOT allows_quantities OR max_selections IS NULL OR max_selections >= 2)');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_add_on_groups');
    }
};
