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

    /**
     * Put back because the order was changed before it was accepted.
     *
     * ReviseOrder hands back everything the order was holding and then takes
     * what it now asks for, as a fresh OrderPlaced. Both rows stay, so the
     * history reads as what happened rather than as a count that jumped.
     */
    case OrderRevised = 'order-revised';

    public function label(): string
    {
        return match ($this) {
            self::Restock => 'Restocked',
            self::Count => 'Counted',
            self::OrderPlaced => 'Ordered',
            self::OrderCancelled => 'Order cancelled',
            self::OrderRevised => 'Order changed',
        };
    }

    /**
     * The reasons that together say what an order is holding right now.
     *
     * An order takes stock as OrderPlaced and hands it back as OrderRevised,
     * and it may do both several times before it is accepted. The **net** of
     * those rows is what is still out against it, which is exactly what
     * CancelOrder has to put back — summing OrderPlaced alone would give back
     * every take including the ones already reversed.
     *
     * @return list<string>
     */
    public static function heldByOrderValues(): array
    {
        return [self::OrderPlaced->value, self::OrderRevised->value];
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
            self::OrderRevised => 'info',
        };
    }
}
