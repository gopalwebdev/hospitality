<?php

namespace App\Enums;

/**
 * The guest's intent to pay now or add it to the bill. Stored on orders.settlement.
 *
 * This is intent, not the truth about the money — payments and order_payments
 * are that. Its job is the panel's worklist: which orders want a payment taken
 * now, and which are running up a bill to settle at checkout.
 */
enum OrderSettlement: string
{
    case PayNow = 'pay-now';

    case AddToBill = 'add-to-bill';

    public function label(): string
    {
        return match ($this) {
            self::PayNow => 'Pay now',
            self::AddToBill => 'Add to bill',
        };
    }

    /**
     * The colour of the badge shown beside it in the panel.
     */
    public function color(): string
    {
        return match ($this) {
            self::PayNow => 'warning',
            self::AddToBill => 'gray',
        };
    }

    /**
     * Every settlement, keyed by stored value, for a select field.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static function (array $options, self $settlement): array {
                $options[$settlement->value] = $settlement->label();

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
        return array_map(static fn (self $settlement): string => $settlement->value, self::cases());
    }
}
