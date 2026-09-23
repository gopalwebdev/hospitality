<?php

namespace App\Enums;

/**
 * What kind of business a tenant is. Stored on tenants.type, whose CHECK
 * constraint is built from these cases.
 */
enum TenantType: string
{
    case Hotel = 'hotel';

    case Restaurant = 'restaurant';

    case Hospital = 'hospital';

    public function label(): string
    {
        return match ($this) {
            self::Hotel => 'Hotel',
            self::Restaurant => 'Restaurant',
            self::Hospital => 'Hospital',
        };
    }

    /**
     * The kind of location this type of tenant starts with — a hotel or a
     * hospital wants rooms, a restaurant wants tables. What LocationForm
     * preselects on a new row.
     *
     * Read by TenantSeeder to pick sensible starting data, and by
     * LocationForm as the kind select's default. See locationKinds() for
     * the first thing a tenant's type actually decides.
     */
    public function defaultLocationKind(): LocationKind
    {
        return match ($this) {
            self::Hotel, self::Hospital => LocationKind::Room,
            self::Restaurant => LocationKind::Table,
        };
    }

    /**
     * The kinds of location this type of tenant may create — a hotel is
     * never offered "Table". This is the first thing a tenant's type
     * actually decides (`.ai/rules/enums.md` used to say the type "decides
     * nothing yet"; that stopped being true here).
     *
     * A tenant's type stays freely editable even so: this only narrows what
     * a *new* location may be created as. An existing location keeps
     * whatever kind it already holds — nothing rewrites locations.kind
     * underneath a tenant when its type changes — which is why
     * LocationForm's kind select also offers the record's own kind even
     * when it falls outside this list.
     *
     * @return list<LocationKind>
     */
    public function locationKinds(): array
    {
        return match ($this) {
            self::Hotel, self::Hospital => [LocationKind::Room, LocationKind::Area],
            self::Restaurant => [LocationKind::Table, LocationKind::Area],
        };
    }

    /**
     * locationKinds(), keyed by stored value, for a select field.
     *
     * @return array<string, string>
     */
    public function locationKindOptions(): array
    {
        return array_reduce(
            $this->locationKinds(),
            static function (array $options, LocationKind $kind): array {
                $options[$kind->value] = $kind->label();

                return $options;
            },
            [],
        );
    }

    /**
     * Every type, keyed by stored value, for a select field.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $type): array {
                $options[$type->value] = $type->label();

                return $options;
            },
            [],
        );
    }
}
