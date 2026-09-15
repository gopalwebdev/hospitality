<?php

namespace App\Enums;

/**
 * Where an order stands.
 *
 * Two cases for now. The steps staff move an order through — accepted,
 * preparing, served — arrive with the screens that move it; until then an
 * order is either placed or called off.
 */
enum OrderStatus: string
{
    case Placed = 'placed';

    /** Called off. CancelOrder has put back whatever it took from stock. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Placed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The colour of the badge shown beside it in the panel.
     */
    public function color(): string
    {
        return match ($this) {
            self::Placed => 'success',
            self::Cancelled => 'gray',
        };
    }

    /**
     * Every status, keyed by stored value, for a select or a filter.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $status): array {
                $options[$status->value] = $status->label();

                return $options;
            },
            [],
        );
    }
}
