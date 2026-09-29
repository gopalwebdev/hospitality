<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The stretch of days the orders list is being read over.
 *
 * The list **opens on Today**, because almost every question staff ask of it
 * is about the shift they are working. The other cases are the ones they
 * reach for next, and `Custom` hands over to a pair of date pickers for
 * anything else. Leaving the filter empty is "any time" and is how the whole
 * history is read — a preset with no way out would be a floor nobody could
 * get under.
 *
 * An enum rather than five strings in a select, so the label and the days
 * each one means are declared together and `range()` is a `match`: a case
 * added later has to say what it covers rather than silently filtering
 * nothing (`.ai/rules/enums.md`).
 *
 * Days are `config('app.timezone')`'s, the only timezone there is
 * (`.ai/rules/app.md`).
 */
enum OrderPeriod: string
{
    case Today = 'today';

    case Yesterday = 'yesterday';

    case LastSevenDays = 'last_seven_days';

    case ThisMonth = 'this_month';

    /** Whatever the two date pickers beside it say. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Yesterday => 'Yesterday',
            self::LastSevenDays => 'Last 7 days',
            self::ThisMonth => 'This month',
            self::Custom => 'Between two dates',
        };
    }

    /**
     * The first and last day this covers, or nulls for `Custom`, whose days
     * are the two pickers' and not this enum's to know.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public function range(): array
    {
        $today = Date::now()->startOfDay();

        return match ($this) {
            self::Today => [$today, $today],
            self::Yesterday => [$today->subDay(), $today->subDay()],
            // Seven days including today, so "last 7 days" on a Monday
            // reaches back to the Tuesday and not the Monday before it.
            self::LastSevenDays => [$today->subDays(6), $today],
            self::ThisMonth => [$today->startOfMonth(), $today],
            self::Custom => [null, null],
        };
    }

    /**
     * Every period, keyed by stored value, for a select field.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $period): array {
                $options[$period->value] = $period->label();

                return $options;
            },
            [],
        );
    }
}
