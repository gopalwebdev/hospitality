<?php

namespace App\Actions\Orders;

use App\Actions\Baskets\PriceBasket;
use App\Actions\Inventory\ApplyStockChanges;
use App\Actions\Inventory\StockChange;
use App\Actions\Inventory\StockDemand;
use App\Enums\Locale;
use App\Enums\OrderRefusal;
use App\Enums\OrderSettlement;
use App\Enums\StockMovementReason;
use App\Exceptions\InsufficientStock;
use App\Exceptions\OrderRefused;
use App\Models\Location;
use App\Models\Menu;
use App\Models\MenuAddOnOption;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderLine;
use App\Models\StockMovement;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Change what is on an order the kitchen has not accepted yet.
 *
 * The project owner's rule: a guest rings back to add a coffee, and until
 * somebody has picked the order up staff should be able to change it rather
 * than cancel it and type the whole thing again. Once it is
 * OrderStatus::Accepted its lines are fixed — somebody is cooking to them — and
 * this refuses outright.
 *
 * **The order keeps its number**, and everything else is written again. What
 * happens inside the one transaction, in this order and for these reasons:
 *
 * 1. **Locked**, and refused unless it is still open to changes and no live
 *    payment stands against it. Money already taken against a total this is
 *    about to change would leave the two disagreeing, so staff void the payment
 *    first — the same rule, and the same reason, as CancelOrder's.
 * 2. **Priced first**, by the very PriceBasket the guest app asks, and refused
 *    whole if any line no longer stands. Nothing is written for a basket that
 *    would not be accepted as a new order either.
 * 3. **Everything it was holding goes back** (StockMovementReason::OrderRevised),
 *    read from its own movements rather than worked out again from its lines —
 *    a combo's contents may have changed since, and only the movements say what
 *    was actually taken. Then the new basket takes what it needs as a fresh
 *    OrderPlaced. Both rows stay: the history reads as what happened, and
 *    CancelOrder sums the **net** of the two (StockMovementReason::heldByOrderValues()).
 * 4. **Its lines and charges are deleted and written again** by the same
 *    CopyBasketOntoOrder that wrote them the first time, so a revised order is
 *    a copy exactly as a new one is — names in every language, money as priced.
 * 5. **Its totals are replaced** with the new pricing, the GST split included.
 *
 * Running short between the pricing and the lock throws InsufficientStock and
 * rolls the whole thing back, so an order is never left half-changed: it either
 * takes everything the new basket needs or stays exactly as it was.
 *
 * @phpstan-import-type BasketLine from PriceBasket
 */
final readonly class ReviseOrder
{
    public function __construct(
        private PriceBasket $priceBasket,
        private StockDemand $stockDemand,
        private ApplyStockChanges $applyStockChanges,
        private CopyBasketOntoOrder $copyBasketOntoOrder,
    ) {}

    /**
     * @param  list<BasketLine>  $lines
     *
     * @throws LogicException when the order has been accepted, cancelled, or a live payment stands against it
     * @throws OrderRefused when a line no longer stands
     * @throws InsufficientStock when a counted item or option has fewer left than the new basket asks for
     */
    public function __invoke(
        Tenant $tenant,
        Order $order,
        Menu $menu,
        array $lines,
        ?Location $location = null,
        OrderSettlement $settlement = OrderSettlement::AddToBill,
        ?string $locationLabel = null,
        ?string $note = null,
    ): Order {
        throw_if($lines === [], LogicException::class, 'An order cannot be changed to nothing; cancel it instead.');

        $priced = ($this->priceBasket)($tenant, $menu, $lines);

        foreach ($priced['lines'] as $line) {
            if ($line['status'] !== PriceBasket::OK) {
                throw new OrderRefused(OrderRefusal::LinesChanged, $priced['lines']);
            }
        }

        $locationName = match (true) {
            $location instanceof Location => $location->getTranslations('name'),
            is_string($locationLabel) && $locationLabel !== '' => [Locale::default()->value => $locationLabel],
            default => null,
        };

        return DB::transaction(function () use ($tenant, $order, $menu, $lines, $location, $settlement, $locationName, $note, $priced): Order {
            $locked = Order::query()
                ->withoutGlobalScopes()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            throw_unless(
                $locked->status->isOpenToChanges(),
                LogicException::class,
                'This order has been picked up already; it can no longer be changed.',
            );

            $livePaymentId = $locked->paymentAllocations()
                ->whereHas('payment', fn (Builder $payment): Builder => $payment->live())
                ->value('payment_id');

            throw_if(
                $livePaymentId !== null,
                LogicException::class,
                sprintf('Payment #%d still stands against this order; void it before changing what is on it.', $livePaymentId),
            );

            ($this->applyStockChanges)($this->everythingItHolds($locked), StockMovementReason::OrderRevised, $locked);
            ($this->applyStockChanges)(($this->stockDemand)($lines), StockMovementReason::OrderPlaced, $locked);

            // Its choices go with its lines: order_line_choices is cascaded
            // from order_lines, so deleting the lines takes them with it.
            OrderLine::query()->where('order_id', $locked->getKey())->delete();
            OrderCharge::query()->where('order_id', $locked->getKey())->delete();

            $locked->fill([
                'location_name' => $locationName,
                'settlement' => $settlement,
                'note' => $note,
                'subtotal' => $priced['subtotal'],
                'is_union_territory' => $priced['isUnionTerritory'],
                'tax' => $priced['tax'],
                'cgst' => $priced['taxParts']->cgst,
                'sgst' => $priced['taxParts']->sgst,
                'charges_total' => array_sum(array_column($priced['charges'], 'amount')),
                'total' => $priced['total'],
                'prices_include_tax' => $priced['pricesIncludeTax'],
            ]);

            $locked->forceFill([
                'menu_id' => $menu->getKey(),
                'location_id' => $location?->getKey(),
            ])->save();

            ($this->copyBasketOntoOrder)($tenant, $locked, $lines, $priced['lines'], $priced['charges']);

            return $locked;
        });
    }

    /**
     * What this order still has out against it, as changes that give it all back.
     *
     * The net of its own takes and give-backs, so an order changed twice hands
     * back what it is holding now rather than every take it ever made. A row
     * nobody counts any more gets nothing back, and one deleted since took its
     * movements with it.
     *
     * @return list<StockChange>
     */
    private function everythingItHolds(Order $order): array
    {
        $movements = StockMovement::query()
            ->where('order_id', $order->getKey())
            ->whereIn('reason', StockMovementReason::heldByOrderValues())
            ->get(['id', 'menu_item_id', 'menu_add_on_option_id', 'quantity_change']);

        $changes = [];

        foreach ($movements->groupBy(fn (StockMovement $movement): string => $movement->menu_item_id !== null
            ? 'item-'.$movement->menu_item_id
            : 'option-'.$movement->menu_add_on_option_id) as $rows) {
            $first = $rows->first();
            $back = -1 * (int) $rows->sum('quantity_change');

            if (! $first instanceof StockMovement || $back <= 0) {
                continue;
            }

            $changes[] = $first->menu_item_id !== null
                ? StockChange::add(MenuItem::class, $first->menu_item_id, $back)
                : StockChange::add(MenuAddOnOption::class, (int) $first->menu_add_on_option_id, $back);
        }

        return $changes;
    }
}
