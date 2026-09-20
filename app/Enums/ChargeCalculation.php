<?php

namespace App\Enums;

/**
 * How a charge works out what it adds to a bill.
 *
 * Stored on charges.calculation, whose CHECK constraints are built from these
 * cases. `valueColumn()` is the single place that says which column holds the
 * number — the same shape as HomeTileAction::targetColumn() — and
 * ChargeObserver clears the column a calculation does not use.
 */
enum ChargeCalculation: string
{
    /** A share of the bill, stored in basis points: 10% is 1000. */
    case Percentage = 'percentage';

    /** The same amount on every bill, stored in minor units: ₹20 is 2000. */
    case FixedAmount = 'fixed-amount';

    /**
     * The column on `charges` holding this calculation's number.
     */
    public function valueColumn(): string
    {
        return match ($this) {
            self::Percentage => 'rate',
            self::FixedAmount => 'amount',
        };
    }

    /**
     * Every column any calculation keeps its number in.
     *
     * @return list<string>
     */
    public static function everyValueColumn(): array
    {
        return array_values(array_unique(array_map(
            static fn (self $calculation): string => $calculation->valueColumn(),
            self::cases(),
        )));
    }

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage of the bill',
            self::FixedAmount => 'Fixed amount',
        };
    }

    /**
     * Every calculation, keyed by stored value, for a select field.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $calculation): array {
                $options[$calculation->value] = $calculation->label();

                return $options;
            },
            [],
        );
    }
}
