<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->jsonb('title')->nullable();
            // App\Enums\HomeRowLayout: the row decides how its tiles are drawn.
            $table->string('layout', 32);
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE home_rows ADD CONSTRAINT home_rows_position_not_negative CHECK ("position" >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('home_rows');
    }
};
