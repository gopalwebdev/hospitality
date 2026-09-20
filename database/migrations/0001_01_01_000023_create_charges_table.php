<?php

use App\Enums\ChargeCalculation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->jsonb('name');
            $table->string('calculation', 32);
            // Exactly one of these is filled: the one ChargeCalculation::valueColumn() names.
            $table->smallInteger('rate')->nullable();
            $table->integer('amount')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        $calculations = collect(ChargeCalculation::cases())
            ->map(fn (ChargeCalculation $calculation): string => "'{$calculation->value}'")
            ->implode(', ');

        $percentage = ChargeCalculation::Percentage->value;
        $fixedAmount = ChargeCalculation::FixedAmount->value;

        DB::statement("ALTER TABLE charges
            ADD CONSTRAINT charges_calculation_is_known CHECK (calculation IN ({$calculations})),
            ADD CONSTRAINT charges_value_matches_calculation CHECK (
                (calculation = '{$percentage}' AND rate IS NOT NULL AND amount IS NULL)
                OR (calculation = '{$fixedAmount}' AND amount IS NOT NULL AND rate IS NULL)
            ),
            ADD CONSTRAINT charges_rate_in_range CHECK (rate IS NULL OR rate BETWEEN 0 AND 10000),
            ADD CONSTRAINT charges_amount_not_negative CHECK (amount IS NULL OR amount >= 0),
            ADD CONSTRAINT charges_position_not_negative CHECK (\"position\" >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
