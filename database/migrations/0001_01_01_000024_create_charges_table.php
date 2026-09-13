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
            $table->smallInteger('rate_basis_points')->nullable();
            $table->integer('amount_minor_units')->nullable();
            // False limits the charge to the menus in charge_menu.
            $table->boolean('applies_to_all_menus')->default(true);
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
                (calculation = '{$percentage}' AND rate_basis_points IS NOT NULL AND amount_minor_units IS NULL)
                OR (calculation = '{$fixedAmount}' AND amount_minor_units IS NOT NULL AND rate_basis_points IS NULL)
            ),
            ADD CONSTRAINT charges_rate_in_range CHECK (rate_basis_points IS NULL OR rate_basis_points BETWEEN 0 AND 10000),
            ADD CONSTRAINT charges_amount_not_negative CHECK (amount_minor_units IS NULL OR amount_minor_units >= 0),
            ADD CONSTRAINT charges_position_not_negative CHECK (\"position\" >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
