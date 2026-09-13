<?php

namespace App\Enums;

use Illuminate\Support\Arr;

/**
 * What kind of business a tenant is.
 *
 * The platform began with restaurants and serves hotels too. Everything a
 * tenant runs — its panel, its menus, its home screen — is the same for both,
 * so for now the type decides one thing: the word the application uses when it
 * talks to that tenant's own admins and guests ("This hotel already has a menu
 * with that name"). The product team's panel says "tenant" whatever the type.
 *
 * Stored on `tenants.type`, whose CHECK constraint lists the same values, so
 * adding a case means a migration replacing `tenants_type_is_known` as well.
 */
enum TenantType: string
{
    case Hotel = 'hotel';

    case Restaurant = 'restaurant';

    public function label(): string
    {
        return match ($this) {
            self::Hotel => 'Hotel',
            self::Restaurant => 'Restaurant',
        };
    }

    /**
     * What a sentence calls a tenant of this type: "this hotel", "this restaurant".
     */
    public function noun(): string
    {
        return match ($this) {
            self::Hotel => 'hotel',
            self::Restaurant => 'restaurant',
        };
    }

    /**
     * Every type's noun as one phrase — "hotel or restaurant" — for a sentence
     * written before any tenant is known, such as the tenant panel's sign-in page.
     */
    public static function anyNoun(): string
    {
        return Arr::join(
            array_map(static fn (self $type): string => $type->noun(), self::cases()),
            ', ',
            ' or ',
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
