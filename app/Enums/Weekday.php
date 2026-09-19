<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * A day of the week a tenant keeps hours on.
 *
 * Hours repeat weekly and nothing here names a date: a tenant says "closed on
 * Mondays", not "closed on the 14th". A holiday on one particular day is a
 * calendar, which is a different feature and deliberately not this one.
 */
enum Weekday: string
{
    case Monday = 'monday';

    case Tuesday = 'tuesday';

    case Wednesday = 'wednesday';

    case Thursday = 'thursday';

    case Friday = 'friday';

    case Saturday = 'saturday';

    case Sunday = 'sunday';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The three letters a narrow column has room for.
     */
    public function shortLabel(): string
    {
        return substr($this->label(), 0, 3);
    }

    /**
     * The day before this one, for a window that runs past midnight.
     */
    public function previous(): self
    {
        $week = self::week();
        $position = array_search($this, $week, strict: true);

        return $week[($position === false ? 0 : $position + count($week) - 1) % count($week)];
    }

    /**
     * The week in the order it is read and worked, Monday first.
     *
     * @return list<self>
     */
    public static function week(): array
    {
        return self::cases();
    }

    /**
     * The day a moment falls on, in the application's own timezone.
     */
    public static function on(CarbonImmutable $moment): self
    {
        return match ($moment->dayOfWeekIso) {
            1 => self::Monday,
            2 => self::Tuesday,
            3 => self::Wednesday,
            4 => self::Thursday,
            5 => self::Friday,
            6 => self::Saturday,
            default => self::Sunday,
        };
    }

    /**
     * Every day, keyed by stored value, for a select or a filter.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $weekday): array {
                $options[$weekday->value] = $weekday->label();

                return $options;
            },
            [],
        );
    }
}
