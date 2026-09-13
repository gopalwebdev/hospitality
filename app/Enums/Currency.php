<?php

namespace App\Enums;

/**
 * The currency a tenant prices its menu in.
 *
 * India is the only market for now (see .ai/rules/app.md), so there is
 * exactly one case. Kept as an enum rather than a constant so a second market
 * is a case added here, not a rewrite of every place currency is read.
 */
enum Currency: string
{
    case IndianRupee = 'INR';

    /**
     * The symbol shown next to a price.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::IndianRupee => '₹',
        };
    }

    /**
     * How many digits the minor unit has.
     *
     * Money is stored as an integer count of minor units, so this is what turns
     * a stored 24950 into ₹249.50 and back. A match rather than a constant is
     * the point: adding a zero-decimal currency such as JPY must not silently
     * divide by 100.
     */
    public function minorUnitDigits(): int
    {
        return match ($this) {
            self::IndianRupee => 2,
        };
    }

    /**
     * How many minor units make one major unit.
     */
    public function minorUnitsPerMajor(): int
    {
        return 10 ** $this->minorUnitDigits();
    }

    /**
     * Render a stored minor-unit amount as money.
     */
    public function format(int $minorUnits): string
    {
        return $this->symbol().number_format(
            $minorUnits / $this->minorUnitsPerMajor(),
            $this->minorUnitDigits(),
        );
    }

    /**
     * Turn a typed major-unit amount into the integer that gets stored.
     *
     * Rounding happens here, at the edge, and once: everything past this point
     * is exact integer arithmetic.
     */
    public function toMinorUnits(float|int|string $majorUnits): int
    {
        return (int) round(((float) $majorUnits) * $this->minorUnitsPerMajor());
    }

    /**
     * Turn a stored amount back into the major-unit value a form edits.
     */
    public function toMajorUnits(int $minorUnits): float
    {
        return $minorUnits / $this->minorUnitsPerMajor();
    }
}
