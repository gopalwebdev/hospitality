<?php

namespace App\Actions\Orders;

use App\Actions\Inventory\ApplyStockChanges;
use App\Actions\Inventory\StockDemand;
use App\Actions\Menus\QuoteBasket;
use App\Enums\Locale;
use App\Enums\OrderLineType;
use App\Enums\OrderRefusal;
use App\Enums\StockMovementReason;
use App\Exceptions\InsufficientStock;
use App\Exceptions\OrderRefused;
use App\Models\Charge;
use App\Models\Menu;
use App\Models\MenuAddOnOption;
use App\Models\MenuCombo;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderLine;
use App\Models\OrderLineChoice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Place a guest's basket as an order, taking what it needs from stock.
 *
 * The basket is priced by the same QuoteBasket the guest app asks, and refused
 * outright when any line no longer stands. Then, in one transaction, the order
 * is written, stock is taken under a lock (ApplyStockChanges) and the lines are
 * copied in. Something running out between the quote and the lock throws
 * InsufficientStock and rolls the order back with it: an order either takes
 * everything it needs or never existed.
 *
 * Names are copied in every language they have, and money as it was priced, so
 * nothing a tenant changes later rewrites what a guest ordered.
 *
 * @phpstan-import-type BasketLine from QuoteBasket
 */
final readonly class PlaceOrder
{
    public function __construct(
        private QuoteBasket $quoteBasket,
        private StockDemand $stockDemand,
        private ApplyStockChanges $applyStockChanges,
    ) {}

    /**
     * @param  list<BasketLine>  $lines
     *
     * @throws OrderRefused when ordering is off, the menu is not being served, or a line no longer stands
     * @throws InsufficientStock when a counted item or option has fewer left than the basket asks for
     */
    public function __invoke(Tenant $tenant, Menu $menu, array $lines, ?string $locationLabel = null, ?string $note = null): Order
    {
        throw_unless($tenant->isOpenAt(), OrderRefused::class, OrderRefusal::StoreClosed);

        throw_unless($menu->isBeingServedAt(), OrderRefused::class, OrderRefusal::NotBeingServed);

        $quote = ($this->quoteBasket)($tenant, $menu, $lines);

        foreach ($quote['lines'] as $priced) {
            if ($priced['status'] !== QuoteBasket::OK) {
                throw new OrderRefused(OrderRefusal::LinesChanged, $quote['lines']);
            }
        }

        return DB::transaction(function () use ($tenant, $menu, $lines, $locationLabel, $note, $quote): Order {
            $order = new Order([
                'location_label' => $locationLabel,
                'note' => $note,
                'subtotal_minor_units' => $quote['subtotalMinorUnits'],
                'tax_minor_units' => $quote['taxMinorUnits'],
                'charges_minor_units' => array_sum(array_column($quote['charges'], 'amountMinorUnits')),
                'total_minor_units' => $quote['totalMinorUnits'],
                'prices_include_tax' => $quote['pricesIncludeTax'],
            ]);

            $order->forceFill(['tenant_id' => $tenant->getKey(), 'menu_id' => $menu->getKey()])->save();

            ($this->applyStockChanges)(($this->stockDemand)($lines), StockMovementReason::OrderPlaced, $order);

            $this->copyLines($tenant, $order, $lines, $quote['lines']);
            $this->copyCharges($order, $quote['charges']);

            return $order;
        });
    }

    /**
     * Copy each basket line onto the order, with the choices made on it.
     *
     * @param  list<BasketLine>  $lines
     * @param  list<array{key: string, status: string, unitPriceMinorUnits: int, totalMinorUnits: int}>  $priced  in the same order as the lines
     */
    private function copyLines(Tenant $tenant, Order $order, array $lines, array $priced): void
    {
        $tenantRate = $tenant->taxRateBasisPoints();
        $tenantOverrides = $tenant->overridesItemTaxRates();

        $items = $this->named(MenuItem::query(), $this->idsOf($lines, QuoteBasket::ITEM), ['id', 'name', 'tax_rate_basis_points']);
        $combos = $this->named(MenuCombo::query(), $this->idsOf($lines, QuoteBasket::COMBO), ['id', 'name', 'tax_rate_basis_points']);
        $options = $this->named(MenuAddOnOption::query(), $this->optionIdsOf($lines), ['id', 'name', 'price_minor_units']);

        foreach ($lines as $index => $line) {
            $isCombo = $line['type'] === QuoteBasket::COMBO;
            $ordered = $isCombo ? $combos->get((int) $line['id']) : $items->get((int) $line['id']);

            throw_unless($ordered instanceof MenuItem || $ordered instanceof MenuCombo, LogicException::class, 'A line priced a moment ago has no item or combo to copy.');

            $orderLine = new OrderLine([
                'type' => $isCombo ? OrderLineType::Combo : OrderLineType::Item,
                'menu_item_id' => $isCombo ? null : $ordered->getKey(),
                'menu_combo_id' => $isCombo ? $ordered->getKey() : null,
                'name' => $ordered->getTranslations('name'),
                'quantity' => (int) $line['quantity'],
                'unit_price_minor_units' => $priced[$index]['unitPriceMinorUnits'],
                'total_minor_units' => $priced[$index]['totalMinorUnits'],
                'tax_rate_basis_points' => $ordered->taxRateBasisPoints($tenantRate, $tenantOverrides),
                'position' => $index,
            ]);

            $orderLine->forceFill(['tenant_id' => $order->tenant_id, 'order_id' => $order->getKey()])->save();

            foreach ($this->choiceQuantitiesOf($line) as $optionId => $quantity) {
                $option = $options->get($optionId);

                throw_unless($option instanceof MenuAddOnOption, LogicException::class, 'A choice priced a moment ago has no option to copy.');

                $choice = new OrderLineChoice([
                    'menu_add_on_option_id' => $option->getKey(),
                    'name' => $option->getTranslations('name'),
                    'quantity' => $quantity,
                    'price_minor_units' => $option->price_minor_units,
                ]);

                $choice->forceFill(['tenant_id' => $order->tenant_id, 'order_line_id' => $orderLine->getKey()])->save();
            }
        }
    }

    /**
     * Copy the charges the bill carried, at what they came to.
     *
     * @param  list<array{id: int, name: string, amountMinorUnits: int}>  $charges
     */
    private function copyCharges(Order $order, array $charges): void
    {
        if ($charges === []) {
            return;
        }

        $names = $this->named(Charge::query(), array_column($charges, 'id'), ['id', 'name']);

        foreach ($charges as $position => $charge) {
            $orderCharge = new OrderCharge([
                'charge_id' => $charge['id'],
                'name' => $names->get($charge['id'])?->getTranslations('name') ?? [Locale::default()->value => $charge['name']],
                'amount_minor_units' => $charge['amountMinorUnits'],
                'position' => $position,
            ]);

            $orderCharge->forceFill(['tenant_id' => $order->tenant_id, 'order_id' => $order->getKey()])->save();
        }
    }

    /**
     * The named rows, keyed, with only the columns a copy needs; no query when there are none.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $ids
     * @param  list<string>  $columns
     * @return EloquentCollection<int, TModel>
     */
    private function named(Builder $query, array $ids, array $columns): EloquentCollection
    {
        if ($ids === []) {
            return new EloquentCollection;
        }

        return $query->withoutGlobalScopes()->whereKey($ids)->get($columns)->keyBy(fn (Model $row): int => (int) $row->getKey());
    }

    /**
     * The ids of one kind of line.
     *
     * @param  list<BasketLine>  $lines
     * @return list<int>
     */
    private function idsOf(array $lines, string $type): array
    {
        return array_values(array_unique(array_map(
            static fn (array $line): int => (int) $line['id'],
            array_filter($lines, static fn (array $line): bool => $line['type'] === $type),
        )));
    }

    /**
     * Every option picked on any line.
     *
     * @param  list<BasketLine>  $lines
     * @return list<int>
     */
    private function optionIdsOf(array $lines): array
    {
        $ids = [];

        foreach ($lines as $line) {
            foreach ($line['choices'] ?? [] as $choice) {
                $ids[(int) $choice['optionId']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * How many of each option one line's item was given, an option named twice counted once.
     *
     * @param  BasketLine  $line
     * @return array<int, int>
     */
    private function choiceQuantitiesOf(array $line): array
    {
        $quantities = [];

        foreach ($line['choices'] ?? [] as $choice) {
            $quantities[(int) $choice['optionId']] = ($quantities[(int) $choice['optionId']] ?? 0) + (int) $choice['quantity'];
        }

        return $quantities;
    }
}
