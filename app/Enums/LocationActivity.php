<?php

namespace App\Enums;

/**
 * What is happening at one location right now, worked out from its orders.
 *
 * Never stored. `.ai/rules/locations.md` settled that `locations` carries
 * nothing about occupancy — that is a fact about the orders placed there, not
 * about the room — so this is derived on every read of the orders page's floor and
 * there is no column behind it.
 *
 * "Open" means a placed order that still owes money: a cancelled one is not
 * activity, and one already settled is finished business.
 */
enum LocationActivity: string
{
    /** Nothing placed here is still owing. */
    case Clear = 'clear';

    /** Its newest open order landed within ReadLocationActivity::NEW_ORDER_MINUTES. */
    case JustOrdered = 'just-ordered';

    /** Open orders, none of them new: a bill is running here. */
    case Running = 'running';

    public function label(): string
    {
        return match ($this) {
            self::Clear => 'Clear',
            self::JustOrdered => 'New order',
            self::Running => 'Open bill',
        };
    }

    /**
     * The colour of the badge and the card's edge on a location card.
     */
    public function color(): string
    {
        return match ($this) {
            self::Clear => 'gray',
            self::JustOrdered => 'warning',
            self::Running => 'info',
        };
    }

    /**
     * Whether anything here is waiting on staff.
     */
    public function isOpen(): bool
    {
        return $this !== self::Clear;
    }

    /**
     * Every activity, keyed by stored value, for a filter.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $activity): array {
                $options[$activity->value] = $activity->label();

                return $options;
            },
            [],
        );
    }
}
