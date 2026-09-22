<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

    }

    public function down(): void
    {
        Schema::dropIfExists('home_tiles');
    }
};
