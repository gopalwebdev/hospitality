<?php

namespace App\Enums;

/**
 * Why a count changed, on every row of `stock_movements`.
 *
 * The count on an item or an option says how many are left; the movements say
 * how it got there. Square, Shopify and Toast all keep the same pair, so a count
 * that looks wrong at the end of an evening can be read back line by line.
 */
enum StockMovementReason: string
{
    /** More arrived: "Add 10". */
    case Restock = 'restock';

    /** Counted and set by hand — including the first count, when tracking starts. */
    case Count = 'count';

    /** Taken by an order a guest placed. */
    case OrderPlaced = 'order-placed';

    /** Put back because that order was cancelled. */
    case OrderCancelled = 'order-cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Restock => 'Restocked',
            self::Count => 'Counted',
            self::OrderPlaced => 'Ordered',
            self::OrderCancelled => 'Order cancelled',
        };
    }

    /**
     * The colour of the badge shown beside it in the panel.
     */
    public function color(): string
    {
        return match ($this) {
            self::Restock => 'success',
            self::Count => 'info',
            self::OrderPlaced => 'warning',
            self::OrderCancelled => 'gray',
        };
    }
}
