<?php

namespace App\Enums;

/**
 * How a payment was taken. Stored on payments.method.
 *
 * Two questions about a method are answered here and nowhere else — the same
 * shape as ChargeCalculation::valueColumn() / HomeTileAction::targetColumn():
 * `deviceKind()` says which device a method may be recorded against, and
 * `takesReference()` says whether it carries a transaction number.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';

    case Upi = 'upi';

    case CreditCard = 'credit-card';

    case DebitCard = 'debit-card';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Upi => 'UPI',
            self::CreditCard => 'Credit card',
            self::DebitCard => 'Debit card',
        };
    }

    /**
     * The colour of the badge shown beside it in the panel.
     */
    public function color(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::Upi => 'info',
            self::CreditCard, self::DebitCard => 'warning',
        };
    }

    /**
     * Which kind of device a payment taken this way may be recorded against.
     * Null means the method names no device at all — cash changes no hands
     * through a machine.
     */
    public function deviceKind(): ?PaymentDeviceKind
    {
        return match ($this) {
            self::Cash => null,
            self::Upi => PaymentDeviceKind::QrCode,
            self::CreditCard, self::DebitCard => PaymentDeviceKind::CardMachine,
        };
    }

    /**
     * Whether this method carries a transaction / UTR / approval number.
     */
    public function takesReference(): bool
    {
        return $this !== self::Cash;
    }

    /**
     * Every method, keyed by stored value, for a select field.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $method): array {
                $options[$method->value] = $method->label();

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
        return array_map(static fn (self $method): string => $method->value, self::cases());
    }
}
