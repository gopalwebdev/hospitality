<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->time('available_from')->nullable();
            $table->time('available_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE menus
            ADD CONSTRAINT menus_service_window_paired CHECK ((available_from IS NULL) = (available_until IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
