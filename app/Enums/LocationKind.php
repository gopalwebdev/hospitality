<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * What a location is: a room, a table, or a delivery point.
 *
 * One generic module rather than separate Rooms and Tables — a hotel wants
 * rooms, a restaurant wants tables, and both want a handful of points like a
 * pool or an entrance that only makes sense as its own case. Which of them a
 * tenant is offered follows its type (TenantType::locationKinds()). Stored on
 * locations.kind; every case is somewhere an order can go.
 */
enum LocationKind: string
{
    case Room = 'room';

    case Table = 'table';

    /** A delivery point that is neither a room nor a table: a pool, an entrance, a beach. */
    case Area = 'area';

    public function label(): string
    {
        return match ($this) {
            self::Room => 'Room',
            self::Table => 'Table',
            self::Area => 'Area',
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
        };
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
}
