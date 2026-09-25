<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The kitchen picks an order up: from placed to accepted.
 *
 * One line of work and a lock around it, because what it decides is not small —
 * accepting is the moment an order's lines stop being changeable. Somebody is
 * cooking to them from here, so ReviseOrder refuses an accepted order and the
 * panel stops offering to change it.
 *
 * Nothing about stock or money changes: the order took what it needed when it
 * was placed, and it still owes exactly what it owed. Only cancelling ends an
 * order, and it still may be cancelled after this.
 */
final readonly class AcceptOrder
{
    /**
     * @throws LogicException when the order is not one waiting to be picked up
     */
    public function __invoke(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            // Locked, so two people accepting at once do not both believe they
            // were the one who did, and a revision landing at the same moment
            // queues behind it rather than changing an accepted order.
            $locked = Order::query()
                ->withoutGlobalScopes()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail(['id', 'tenant_id', 'status']);

            throw_unless($locked->isPlaced(), LogicException::class, 'Only an order nobody has picked up yet can be accepted.');

            $locked->update(['status' => OrderStatus::Accepted]);

            // The instance the page redraws from has to say accepted too.
            $order->setAttribute('status', OrderStatus::Accepted)->syncOriginalAttribute('status');
        });
    }
}
