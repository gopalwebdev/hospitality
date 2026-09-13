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
