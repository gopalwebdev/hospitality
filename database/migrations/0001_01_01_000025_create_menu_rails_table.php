<?php

use App\Enums\MenuRailType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_rails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            // App\Enums\MenuRailType. The ones every menu has get a row only once placed.
            $table->string('type', 32);
            // The same number space as the menu's top-level categories; see Menu::readingOrder().
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        $types = collect(MenuRailType::cases())
            ->map(fn (MenuRailType $type): string => "'{$type->value}'")
            ->implode(', ');

        DB::statement("ALTER TABLE menu_rails
            ADD CONSTRAINT menu_rails_type_is_known CHECK (type IN ({$types})),
            ADD CONSTRAINT menu_rails_position_not_negative CHECK (\"position\" >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_rails');
    }
};
