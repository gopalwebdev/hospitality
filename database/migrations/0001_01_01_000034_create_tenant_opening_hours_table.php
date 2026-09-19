<?php

use App\Enums\Weekday;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_opening_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // App\Enums\Weekday. Hours repeat weekly; no row names a date.
            $table->string('weekday', 16);
            // A weekly holiday — closed every Monday — rather than one date off.
            $table->boolean('is_closed')->default(false);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->timestamps();
        });

        $weekdays = collect(Weekday::cases())
            ->map(fn (Weekday $weekday): string => "'{$weekday->value}'")
            ->implode(', ');

        // A day is closed with no hours at all, or open with both of them: one
        // time on its own says nothing about when the doors are open.
        DB::statement("ALTER TABLE tenant_opening_hours
            ADD CONSTRAINT tenant_opening_hours_weekday_is_known CHECK (weekday IN ({$weekdays})),
            ADD CONSTRAINT tenant_opening_hours_closed_has_no_times CHECK (
                (is_closed AND opens_at IS NULL AND closes_at IS NULL)
                OR (NOT is_closed AND opens_at IS NOT NULL AND closes_at IS NOT NULL)
            )");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_opening_hours');
    }
};
