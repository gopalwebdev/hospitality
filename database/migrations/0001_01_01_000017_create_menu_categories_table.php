<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            // Null for a section of the menu, set for a subdivision of one.
            $table->foreignId('parent_id')->nullable()->constrained('menu_categories')->cascadeOnDelete();
            $table->jsonb('name');
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE menu_categories
            ADD CONSTRAINT menu_categories_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id),
            ADD CONSTRAINT menu_categories_position_not_negative CHECK ("position" >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_categories');
    }
};
