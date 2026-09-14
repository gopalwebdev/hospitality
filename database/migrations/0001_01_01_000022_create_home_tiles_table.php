<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_tiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('home_row_id')->constrained()->cascadeOnDelete();
            $table->jsonb('label');
            $table->string('image_path')->nullable();
            // App\Enums\HomeTileAction, and exactly one destination column to match it.
            $table->string('action', 32);
            $table->foreignId('menu_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('document_path')->nullable();
            $table->string('url')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE home_tiles
            ADD CONSTRAINT home_tiles_position_not_negative CHECK (\"position\" >= 0),
            ADD CONSTRAINT home_tiles_destination_matches_action CHECK (
                (action = 'menu' AND menu_id IS NOT NULL AND document_path IS NULL AND url IS NULL)
                OR (action = 'pdf' AND document_path IS NOT NULL AND menu_id IS NULL AND url IS NULL)
                OR (action = 'link' AND url IS NOT NULL AND menu_id IS NULL AND document_path IS NULL)
            )");
    }

    public function down(): void
    {
        Schema::dropIfExists('home_tiles');
    }
};
