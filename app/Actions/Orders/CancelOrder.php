<?php

namespace App\Actions\Orders;

use App\Actions\Inventory\ApplyStockChanges;
use App\Actions\Inventory\StockChange;
use App\Enums\OrderStatus;
use App\Enums\StockMovementReason;
use App\Models\MenuAddOnOption;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Call off a placed order and put back what it took from stock.
 *
 * What goes back is read from the order's own movements, not worked out again
 * from its lines: a combo's contents or an option's count may have changed
 * since, and only the movements say what was actually taken. A row nobody counts
 * any more gets nothing back, and a row deleted since took its movements with it.
 *
 * Also refuses an order a live (unvoided) payment still stands against, before
 * anything is written: staff void the payment first, which is the auditable
 * order and stops stock coming back while money sits recorded against an order
 * that no longer exists.
 */
final readonly class CancelOrder
{
    public function __construct(private ApplyStockChanges $applyStockChanges) {}

    /**
     * @throws LogicException when the order has already been cancelled, or a live payment still stands against it
     */
    public function __invoke(Order $order, ?User $user = null): void
    {
        DB::transaction(function () use ($order, $user): void {
            // Locked, so two people cancelling at once put stock back once.
            $locked = Order::query()
                ->withoutGlobalScopes()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail(['id', 'tenant_id', 'status', 'cancelled_at']);

            throw_unless($locked->isPlaced(), LogicException::class, 'Only a placed order can be cancelled.');

            $livePaymentId = $locked->paymentAllocations()
                ->whereHas('payment', fn (Builder $payment): Builder => $payment->live())
                ->value('payment_id');

            throw_if(
                $livePaymentId !== null,
                LogicException::class,
                sprintf('Payment #%d still stands against this order; void it before cancelling.', $livePaymentId),
            );

            $taken = StockMovement::query()
                ->where('order_id', $locked->getKey())
                ->where('reason', StockMovementReason::OrderPlaced->value)
                ->get(['id', 'menu_item_id', 'menu_add_on_option_id', 'quantity_change']);

            $changes = [];

            foreach ($taken->groupBy(fn (StockMovement $movement): string => $movement->menu_item_id !== null ? 'item-'.$movement->menu_item_id : 'option-'.$movement->menu_add_on_option_id) as $movements) {
                $first = $movements->first();
                $back = -1 * (int) $movements->sum('quantity_change');

                if (! $first instanceof StockMovement || $back <= 0) {
                    continue;
                }

                $changes[] = $first->menu_item_id !== null
                    ? StockChange::add(MenuItem::class, $first->menu_item_id, $back)
                    : StockChange::add(MenuAddOnOption::class, (int) $first->menu_add_on_option_id, $back);
            }

            ($this->applyStockChanges)($changes, StockMovementReason::OrderCancelled, $locked, $user);

            $locked->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);
        });
    }
}
