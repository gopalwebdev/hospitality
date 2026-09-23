<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * What a location is: a room, a table, a delivery point, or a zone grouping others.
 *
 * One generic module rather than separate Rooms and Tables — a hotel wants
 * rooms, a restaurant wants tables, and both want a handful of points like
 * a pool or an entrance that only makes sense as its own case, plus zones
 * ("Floor 2", "Terrace") that group other locations rather than naming a
 * destination of their own. Stored on locations.kind.
 */
enum LocationKind: string
{
    case Room = 'room';

    case Table = 'table';

    /** A delivery point that is neither a room nor a table: a pool, an entrance, a beach. */
    case Area = 'area';

    /** A grouping, not a destination: "Floor 2", "Terrace", "North wing". Never picked on an order — see isDeliverable(). */
    case Zone = 'zone';

    public function label(): string
    {
        return match ($this) {
            self::Room => 'Room',
            self::Table => 'Table',
            self::Area => 'Area',
            self::Zone => 'Zone',
        };
    }

    /**
     * The colour of the badge shown beside it in the panel.
     */
    public function color(): string
    {
        return match ($this) {
            self::Room => 'info',
            self::Table => 'success',
            self::Area => 'gray',
            self::Zone => 'warning',
        };
    }

    /**
     * The icon for this kind's tab on the locations list.
     */
    public function icon(): Heroicon
    {
        return match ($this) {
            self::Room => Heroicon::OutlinedHomeModern,
            self::Table => Heroicon::OutlinedTableCells,
            self::Area => Heroicon::OutlinedMapPin,
            self::Zone => Heroicon::OutlinedRectangleGroup,
        };
    }

    /**
     * Whether a guest's order may actually name this kind of location. The
     * single place this distinction is made, so nothing else compares
     * against Zone directly: a Zone is a grouping, and nothing is ever
     * delivered "to Floor 2".
     */
    public function isDeliverable(): bool
    {
        return $this !== self::Zone;
    }

    /**
     * Whether this kind may hold other locations under it. Zone only — the
     * single place that is said, so a parent's validity is asked here
     * rather than compared against Zone by name.
     */
    public function canHoldChildren(): bool
    {
        return $this === self::Zone;
    }

    /**
     * Every kind, keyed by stored value, for a select field.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $kind): array {
                $options[$kind->value] = $kind->label();

                return $options;
            },
            [],
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * The backing values a guest's order may actually name — never a Zone.
     * The ItemAvailability::orderableValues() precedent, so a query never
     * hardcodes which cases those are and a case added later cannot leave
     * one behind.
     *
     * @return list<string>
     */
    public static function deliverableValues(): array
    {
        return array_values(array_map(
            static fn (self $kind): string => $kind->value,
            array_filter(self::cases(), static fn (self $kind): bool => $kind->isDeliverable()),
        ));
    }
}
