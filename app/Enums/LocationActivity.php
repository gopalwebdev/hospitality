<?php

namespace App\Enums;

/**
 * What is happening at one location right now, worked out from its orders.
 *
 * **This is about work, not money.** The project owner's instruction for the
 * floor: what staff need at a glance is how many orders are waiting, how many
 * are being made and how many are ready to be carried over — not what the room
 * owes. A bill is the list layout's business and the Locations page's Settle.
 * So an order that has been served leaves the floor even though it has not
 * been paid for, and nothing here sums an amount.
 *
 * Never stored. `.ai/rules/locations.md` settled that `locations` carries
 * nothing about occupancy, and that still holds: this is derived on every read
 * and there is no column behind it.
 *
 * The order of the cases is the order of urgency, and `mostUrgent()` is what a
 * card headlines with when a room has several things going on at once:
 * **Ready** first, because the food is made and a guest is waiting for somebody
 * to walk it over; then **Pending**, which nobody has even picked up; then
 * **Preparing**, which is already in hand.
 */
enum LocationActivity: string
{
    /** Made and waiting to be taken over. */
    case Ready = 'ready';

    /** Taken, and nobody has picked it up yet. */
    case Pending = 'pending';

    /** The kitchen has it. */
    case Preparing = 'preparing';

    /** Nothing here needs working. */
    case Clear = 'clear';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Pending => 'Pending',
            self::Preparing => 'Preparing',
            self::Clear => 'Clear',
        };
    }

    /**
     * The colour of the badge and the card's edge.
     */
    public function color(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Pending => 'warning',
            self::Preparing => 'info',
            self::Clear => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Ready => 'heroicon-o-bell-alert',
            self::Pending => 'heroicon-o-clock',
            self::Preparing => 'heroicon-o-fire',
            self::Clear => 'heroicon-o-check',
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
     * The loudest of several, which is what a card headlines with.
     *
     * Read off the order the cases are declared in, so changing the urgency of
     * the floor is changing that order and nothing else.
     */
    public static function mostUrgent(self ...$states): self
    {
        foreach (self::cases() as $case) {
            if (in_array($case, $states, strict: true)) {
                return $case;
            }
        }

        return self::Clear;
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
