<?php

namespace App\Enums;

/**
 * How much of an order is settled. Not a column — worked out from
 * Order::amountPaid() against its total, by Order::paymentState().
 */
enum PaymentState: string
{
    case Unpaid = 'unpaid';

    case PartlyPaid = 'partly-paid';

    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartlyPaid => 'Part paid',
            self::Paid => 'Paid',
        };
    }

    /**
     * The colour of the badge shown beside it in the panel.
     */
    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::PartlyPaid => 'warning',
            self::Paid => 'success',
        };
    }

    /**
     * Every state, keyed by stored value, for a select field.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $state): array {
                $options[$state->value] = $state->label();

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
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
