<?php

namespace App\Enums;

/**
 * What kind of physical device a payment was taken on.
 *
 * Stored on payment_devices.kind. App\Enums\PaymentMethod::deviceKind() is the
 * single place that says which method may name which kind.
 */
enum PaymentDeviceKind: string
{
    case CardMachine = 'card-machine';

    case QrCode = 'qr-code';

    public function label(): string
    {
        return match ($this) {
            self::CardMachine => 'Card machine',
            self::QrCode => 'QR code',
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
