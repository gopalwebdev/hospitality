<?php

namespace App\Actions\Payments;

use App\Models\Order;

/**
 * Spread one amount across several orders' own outstanding, oldest first.
 *
 * Pure arithmetic and no database — RecordPayment is what locks and writes.
 * Kept separate so the checkout case (settling a location's whole running bill
 * with one payment) has one reason to change, apart from what a payment is
 * allowed to do.
 *
 * Every order takes at most what it still owes, and never a negative amount:
 * the earliest orders take their outstanding in full until the amount runs
 * out, and whichever order is on when it does takes only what is left.
 */
final readonly class SpreadAcrossOrders
{
    /**
     * @param  iterable<int, Order>  $orders  oldest first; each order's own amountOutstanding() is read
     * @return array<int, int> order id => how much of the amount was given to it
     */
    public function __invoke(int $amount, iterable $orders): array
    {
        $allocations = [];
        $remaining = max(0, $amount);

        foreach ($orders as $order) {
            if ($remaining <= 0) {
                break;
            }

            $outstanding = $order->amountOutstanding();

            if ($outstanding <= 0) {
                continue;
            }

            $given = min($outstanding, $remaining);

            $allocations[$order->getKey()] = $given;
            $remaining -= $given;
        }

        return $allocations;
    }
}
