<?php

namespace App\Actions\Orders;

use App\Actions\Baskets\PriceBasket;
use App\Actions\Inventory\ApplyStockChanges;
use App\Actions\Inventory\StockDemand;
use App\Enums\Locale;
use App\Enums\OrderRefusal;
use App\Enums\OrderSettlement;
use App\Enums\StockMovementReason;
use App\Exceptions\InsufficientStock;
use App\Exceptions\OrderRefused;
use App\Models\Charge;
use App\Models\Location;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Place a guest's basket as an order, taking what it needs from stock.
 *
 * The basket is priced by the same PriceBasket the guest app asks, and refused
 * outright when any line no longer stands. Then, in one transaction, the order
 * is written, stock is taken under a lock (ApplyStockChanges) and the lines are
 * copied in. Something running out between the pricing and the lock throws
 * InsufficientStock and rolls the order back with it: an order either takes
 * everything it needs or never existed.
 *
 * Names are copied in every language they have, and money as it was priced, so
 * nothing a tenant changes later rewrites what a guest ordered.
 *
 * @phpstan-import-type BasketLine from PriceBasket
 * @phpstan-import-type PricedLine from PriceBasket
 * @phpstan-import-type PricedCharge from PriceBasket
 */
final readonly class PlaceOrder
{
    public function __construct(
        private PriceBasket $priceBasket,
        private StockDemand $stockDemand,
        private ApplyStockChanges $applyStockChanges,
        private CopyBasketOntoOrder $copyBasketOntoOrder,
    ) {}

    /**
     * @param  list<BasketLine>  $lines
     * @param  bool  $allowOutsideHours  staff taking an order themselves, past closing or off a menu's service window
     *
     * @throws OrderRefused when ordering is off, the menu is not being served, or a line no longer stands
     * @throws InsufficientStock when a counted item or option has fewer left than the basket asks for
     */
    public function __invoke(
        Tenant $tenant,
        Menu $menu,
        array $lines,
        ?Location $location = null,
        OrderSettlement $settlement = OrderSettlement::AddToBill,
        ?string $locationLabel = null,
        ?string $note = null,
        bool $allowOutsideHours = false,
    ): Order {
        // The two windows are a guest-facing rule: a phone should not be able
        // to order into a closed kitchen. A member of staff standing in that
        // kitchen is a different question, and the project owner's answer is
        // that they may — an order taken over the phone at five past closing
        // is a real order. Only the tenant panel passes this, and it is
        // deliberately not a setting: every other refusal below still stands,
        // stock included, so what this waives is the clock and nothing else.
        if (! $allowOutsideHours) {
            throw_unless($tenant->isOpenAt(), OrderRefused::class, OrderRefusal::StoreClosed);

            throw_unless($menu->isBeingServedAt(), OrderRefused::class, OrderRefusal::NotBeingServed);
        }

        $priced = ($this->priceBasket)($tenant, $menu, $lines);

        foreach ($priced['lines'] as $line) {
            if ($line['status'] !== PriceBasket::OK) {
                throw new OrderRefused(OrderRefusal::LinesChanged, $priced['lines']);
            }
        }

        // A picked location wins over typed free text — copied in every
        // language it has, so an order is a copy just as its lines are.
        // Free text is stored under the default locale only, the same shape
        // copyCharges() already uses for a charge with no row to copy from.
        $locationName = match (true) {
            $location instanceof Location => $location->getTranslations('name'),
            is_string($locationLabel) && $locationLabel !== '' => [Locale::default()->value => $locationLabel],
            default => null,
        };

        return DB::transaction(function () use ($tenant, $menu, $lines, $location, $settlement, $locationName, $note, $priced): Order {
            $order = new Order([
                'location_name' => $locationName,
                'settlement' => $settlement,
                'note' => $note,
                'subtotal' => $priced['subtotal'],
                // The split and what the state's half is called as the bill was
                // actually priced, never worked out again: a tenant may change
                // its rates, or move, after an order has been placed.
                'is_union_territory' => $priced['isUnionTerritory'],
                'tax' => $priced['tax'],
                'cgst' => $priced['taxParts']->cgst,
                'sgst' => $priced['taxParts']->sgst,
                'charges_total' => array_sum(array_column($priced['charges'], 'amount')),
                'total' => $priced['total'],
                'prices_include_tax' => $priced['pricesIncludeTax'],
            ]);

            $order->forceFill([
                'tenant_id' => $tenant->getKey(),
                'menu_id' => $menu->getKey(),
                'location_id' => $location?->getKey(),
            ])->save();

            ($this->applyStockChanges)(($this->stockDemand)($lines), StockMovementReason::OrderPlaced, $order);

            ($this->copyBasketOntoOrder)($tenant, $order, $lines, $priced['lines'], $priced['charges']);

            return $order;
        });
    }
}
