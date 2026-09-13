<?php

namespace App\Enums;

/**
 * Whether something on the menu can be ordered right now, and why not.
 *
 * A boolean answered "can a guest have this" and nothing else, so a kitchen
 * that had run out of prawns and one that had stopped serving biryani after
 * three o'clock looked identical on the menu — and both looked like the item
 * had been taken off. The reason is worth carrying: a guest reads "sold out"
 * differently from "not available right now", and the kitchen reads the two
 * differently when putting the menu back.
 *
 * Nothing is ever hard-deleted to take it off the menu. That is what these
 * cases are for, and what the delete warnings in the panel say.
 */
enum ItemAvailability: string
{
    case Available = 'available';

    /** Run out. Comes back when the kitchen restocks. */
    case OutOfStock = 'out-of-stock';

    /** Off for now for any other reason — no chef for it, a broken machine. */
    case TemporarilyUnavailable = 'temporarily-unavailable';

    /**
     * Whether a guest may order this right now.
     *
     * The single place the distinction between "showing" and "orderable" is
     * made, so adding a fourth case cannot leave a query behind.
     */
    public function isOrderable(): bool
    {
        return $this === self::Available;
    }

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::OutOfStock => 'Out of stock',
            self::TemporarilyUnavailable => 'Temporarily unavailable',
        };
    }

    /**
     * The colour of the badge shown beside it in the panel.
     */
    public function color(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::OutOfStock => 'danger',
            self::TemporarilyUnavailable => 'warning',
        };
    }

    /**
     * The backing values a guest may actually order.
     *
     * @return list<string>
     */
    public static function orderableValues(): array
    {
        return array_values(array_map(
            static fn (self $availability): string => $availability->value,
            array_filter(self::cases(), static fn (self $availability): bool => $availability->isOrderable()),
        ));
    }

    /**
     * Every availability, keyed by stored value, for a select field.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $availability): array {
                $options[$availability->value] = $availability->label();

                return $options;
            },
            [],
        );
    }
}
