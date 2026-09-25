<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Move an order one step along: taken, being made, ready, handed over.
 *
 * One action for the whole flow rather than one per step, because the flow is
 * one thing and it is written down once — `OrderStatus::next()`. A screen that
 * wants to move an order calls this; it never names the status it is moving to,
 * so a step added between two others needs nothing changed here or there.
 *
 * Locked, and for a reason that matters at the first step: accepting is the
 * moment an order's lines stop being changeable, so a revision landing at the
 * same instant has to queue behind it rather than change an order the kitchen
 * has already picked up.
 *
 * Nothing about stock or money changes at any step. The order took what it
 * needed when it was placed and owes what it owes until it is paid for; only
 * cancelling gives anything back.
 */
final readonly class AdvanceOrder
{
    /**
     * @throws LogicException when the order has nowhere left to go
     */
    public function __invoke(Order $order): OrderStatus
    {
        return DB::transaction(function () use ($order): OrderStatus {
            $locked = Order::query()
                ->withoutGlobalScopes()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail(['id', 'tenant_id', 'status']);

            $next = $locked->status->next();

            throw_unless(
                $next instanceof OrderStatus,
                LogicException::class,
                sprintf('An order that is %s cannot be moved any further along.', mb_strtolower($locked->status->label())),
            );

            $locked->update(['status' => $next]);

            // The instance the page redraws from has to say so too.
            $order->setAttribute('status', $next)->syncOriginalAttribute('status');

            return $next;
        });
    }
}
