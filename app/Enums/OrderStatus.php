<?php

namespace App\Enums;

/**
 * Where an order stands, from taken to handed over.
 *
 * Four steps in one line, and every screen that moves an order moves it one
 * step along it — `next()` is the whole flow, so there is no second place that
 * knows what follows what:
 *
 * **Placed** — taken, nobody has picked it up. The only state its lines may
 * still be changed in (`isOpenToChanges()`, ReviseOrder), because nothing has
 * been made to them yet.
 * **Accepted** — the kitchen has it and is making it.
 * **Ready** — made, waiting to be carried to the room or collected.
 * **Served** — handed over. The work is done; whether it has been *paid* for is
 * a different question entirely (`payments`, `Order::amountOutstanding()`).
 *
 * **Cancelled** is off to the side rather than at the end: an order may be
 * called off while it is still underway, and CancelOrder puts back whatever it
 * was holding. Once it has been served there is nothing to call off — the guest
 * has it — so cancelling is refused and a refund is a payment matter.
 *
 * Two questions the rest of the application asks, and they are not the same one:
 * - `isUnderway()` — is there still work to do? This is what the **floor** draws.
 *   A served order leaves the floor even though it has not been paid for.
 * - `isLive()` — does this order still count at all? This is what **money** asks.
 *   A served order still owes what it owes; only cancelling ends that.
 */
enum OrderStatus: string
{
    case Placed = 'placed';

    case Accepted = 'accepted';

    case Ready = 'ready';

    case Served = 'served';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Pending',
            self::Accepted => 'Preparing',
            self::Ready => 'Ready',
            self::Served => 'Served',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The colour of the badge shown beside it.
     *
     * Ready is the loudest on purpose: it is the one with a guest waiting and
     * the work already done, so it is what a floor should catch the eye with.
     */
    public function color(): string
    {
        return match ($this) {
            self::Placed => 'warning',
            self::Accepted => 'info',
            self::Ready => 'success',
            self::Served => 'gray',
            self::Cancelled => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Placed => 'heroicon-o-clock',
            self::Accepted => 'heroicon-o-fire',
            self::Ready => 'heroicon-o-bell-alert',
            self::Served => 'heroicon-o-check-circle',
            self::Cancelled => 'heroicon-o-x-circle',
        };
    }

    /**
     * The one step that follows this one, or null where the line ends.
     *
     * The single source of truth for the flow. AdvanceOrder moves an order to
     * whatever this returns, and every button that offers to move one reads
     * its label from advanceLabel() rather than naming a status itself.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Placed => self::Accepted,
            self::Accepted => self::Ready,
            self::Ready => self::Served,
            self::Served, self::Cancelled => null,
        };
    }

    /**
     * What the button that takes this order one step further says.
     */
    public function advanceLabel(): ?string
    {
        return match ($this) {
            self::Placed => 'Accept',
            self::Accepted => 'Mark ready',
            self::Ready => 'Hand over',
            self::Served, self::Cancelled => null,
        };
    }

    public function advanceIcon(): ?string
    {
        return $this->next()?->icon();
    }

    /**
     * Whether there is still work to do on this order — what the floor draws.
     *
     * A served order is finished business however much of it is still unpaid,
     * and a cancelled one never has to be worked at all.
     */
    public function isUnderway(): bool
    {
        return match ($this) {
            self::Placed, self::Accepted, self::Ready => true,
            self::Served, self::Cancelled => false,
        };
    }

    /**
     * Whether this order still counts — what money asks.
     *
     * Everything that used to ask isPlaced() and meant "this one still stands"
     * asks this: it still owes what it owes, still takes a payment, and still
     * belongs on a bill.
     */
    public function isLive(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * Whether its lines may still be changed.
     */
    public function isOpenToChanges(): bool
    {
        return $this === self::Placed;
    }

    /**
     * The statuses with work still to do, for a whereIn.
     *
     * @return list<string>
     */
    public static function underwayValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isUnderway());
    }

    /**
     * The statuses that still count, for a whereIn.
     *
     * @return list<string>
     */
    public static function liveValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isLive());
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

    /**
     * @param  callable(self): bool  $matches
     * @return list<string>
     */
    private static function valuesWhere(callable $matches): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), $matches),
        ));
    }
}
