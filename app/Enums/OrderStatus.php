<?php

namespace App\Enums;

/**
 * Where an order stands.
 *
 * Three cases, and the line that matters runs between the first two: a **placed**
 * order is one nobody has picked up yet, so staff may still change it — the guest
 * rang back to add a coffee. **Accepting** it is the kitchen saying it is being
 * made, and from that moment its lines are fixed, because stock has been taken
 * against them and somebody is cooking to them. The project owner's rule:
 * "if the order is not accepted I should be able to modify it; once accepted I
 * cannot."
 *
 * Both of the first two are **live** — still owed for, still on the floor, still
 * cancellable. Only cancelling ends an order, and CancelOrder is what puts back
 * whatever it was holding.
 *
 * The later steps a kitchen moves an order through — preparing, ready, served —
 * are still not here. They arrive with the screens that move it, and nothing in
 * this application needs them to decide anything yet.
 */
enum OrderStatus: string
{
    /** Taken, not yet picked up. The only state its lines may still be changed in. */
    case Placed = 'placed';

    /** The kitchen has it. Its lines are fixed from here on. */
    case Accepted = 'accepted';

    /** Called off. CancelOrder has put back whatever it was holding. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Placed',
            self::Accepted => 'Accepted',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The colour of the badge shown beside it in the panel.
     *
     * Placed is amber rather than green: it is the one that wants somebody to
     * do something. Accepted is the settled, in-hand state.
     */
    public function color(): string
    {
        return match ($this) {
            self::Placed => 'warning',
            self::Accepted => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Placed => 'heroicon-o-clock',
            self::Accepted => 'heroicon-o-check-circle',
            self::Cancelled => 'heroicon-o-x-circle',
        };
    }

    /**
     * Whether an order in this state is still owed for and still on the floor.
     *
     * Everything that means "this order still counts" asks this rather than
     * naming Placed: an accepted order still owes money, still shows at its
     * room, still takes a payment and can still be called off.
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
     * The states an order that still counts is in, for a whereIn.
     *
     * @return list<string>
     */
    public static function liveValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isLive()),
        ));
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
