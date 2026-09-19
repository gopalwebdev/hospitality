<?php

namespace App\Enums;

/**
 * Why an order was refused. Nothing is taken from stock and no order is written for any of them.
 *
 * Sent to the guest app as `reason`, beside a message it may show as it is.
 * Running out has its own exception (InsufficientStock), because it carries
 * what is left, but it answers with the same field.
 */
enum OrderRefusal: string
{
    /** The tenant's doors are shut: outside its hours, or on its weekly holiday. */
    case StoreClosed = 'store-closed';

    /** The menu has a service window, and this is outside it. */
    case NotBeingServed = 'not-being-served';

    /** A line no longer stands: sold out, gone, or its choices break its groups' rules. */
    case LinesChanged = 'lines-changed';

    /** A counted item or option has fewer left than the basket asks for. */
    case InsufficientStock = 'insufficient-stock';

    public function message(): string
    {
        $message = match ($this) {
            self::StoreClosed => __('guest.orders.store_closed'),
            self::NotBeingServed => __('guest.orders.not_being_served'),
            self::LinesChanged => __('guest.orders.lines_changed'),
            self::InsufficientStock => __('guest.orders.insufficient_stock'),
        };

        return is_string($message) ? $message : '';
    }
}
