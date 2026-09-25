<?php

namespace App\Actions\Orders;

use App\Actions\Baskets\PriceBasket;
use App\Enums\Locale;
use App\Enums\OrderLineType;
use App\Models\Charge;
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
use LogicException;

/**
 * Write a priced basket onto an order as its lines, their choices and its charges.
 *
 * **An order is a copy.** Every name is stored in each language it has and every
 * amount as it was priced, so renaming, repricing, recoding or deleting what was
 * ordered never rewrites the order. That is the whole job of this class, and it
 * is its own action because two things do it: PlaceOrder, writing an order for
 * the first time, and ReviseOrder, writing one again after staff changed it
 * before the kitchen accepted it. It lived inside PlaceOrder until the second
 * caller arrived.
 *
 * It writes and never reads a decision: whether the basket may be ordered at all
 * is PriceBasket's answer and its caller's to act on, and taking stock belongs to
 * ApplyStockChanges. Callers run it inside their own transaction.
 *
 * @phpstan-import-type BasketLine from PriceBasket
 * @phpstan-import-type PricedLine from PriceBasket
 * @phpstan-import-type PricedCharge from PriceBasket
 */
final readonly class CopyBasketOntoOrder
{
    /**
     * @param  list<BasketLine>  $lines
     * @param  list<PricedLine>  $priced  in the same order as the lines
     * @param  list<PricedCharge>  $charges
     */
    public function __invoke(Tenant $tenant, Order $order, array $lines, array $priced, array $charges): void
    {
        $this->copyLines($tenant, $order, $lines, $priced);
        $this->copyCharges($order, $charges);
    }

    /**
     * Copy each basket line onto the order, with the choices made on it.
     *
     * @param  list<BasketLine>  $lines
     * @param  list<PricedLine>  $priced  in the same order as the lines
     */
    private function copyLines(Tenant $tenant, Order $order, array $lines, array $priced): void
    {
        $tenantRate = $tenant->taxRate();
        $tenantOverrides = $tenant->overridesItemTaxRates();

        $items = $this->named(MenuItem::query(), $this->idsOf($lines, PriceBasket::ITEM), ['id', 'name', 'tax_rate', 'hsn_sac_code']);
        $combos = $this->named(MenuCombo::query(), $this->idsOf($lines, PriceBasket::COMBO), ['id', 'name', 'tax_rate', 'hsn_sac_code']);
        $options = $this->named(MenuAddOnOption::query(), $this->optionIdsOf($lines), ['id', 'name', 'price', 'tax_rate', 'hsn_sac_code']);

        foreach ($lines as $index => $line) {
            $isCombo = $line['type'] === PriceBasket::COMBO;
            $ordered = $isCombo ? $combos->get((int) $line['id']) : $items->get((int) $line['id']);

            throw_unless($ordered instanceof MenuItem || $ordered instanceof MenuCombo, LogicException::class, 'A line priced a moment ago has no item or combo to copy.');

            $orderLine = new OrderLine([
                'type' => $isCombo ? OrderLineType::Combo : OrderLineType::Item,
                'menu_item_id' => $isCombo ? null : $ordered->getKey(),
                'menu_combo_id' => $isCombo ? $ordered->getKey() : null,
                'name' => $ordered->getTranslations('name'),
                'quantity' => (int) $line['quantity'],
                'unit_price' => $priced[$index]['unitPrice'],
                'total' => $priced[$index]['total'],
                'tax_rate' => $ordered->taxRate($tenantRate, $tenantOverrides),
                'taxable_value' => $priced[$index]['taxableValue'],
                // The line's own CGST and SGST, as priced. An invoice shows
                // them per line, and re-deriving them from the rate later would
                // not add back up to what the order charged.
                ...$priced[$index]['taxParts']->columns(),
                // Copied, because the item may be renamed, recoded or deleted.
                'hsn_sac_code' => $ordered->hsn_sac_code,
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
                    'price' => $option->price,
                    // What it was actually invoiced under: its own where it
                    // states one, the item's where it does not.
                    'tax_rate' => $option->taxRate($orderLine->tax_rate),
                    'hsn_sac_code' => $option->hsn_sac_code ?? $orderLine->hsn_sac_code,
                ]);

                $choice->forceFill(['tenant_id' => $order->tenant_id, 'order_line_id' => $orderLine->getKey()])->save();
            }
        }
    }

    /**
     * Copy the charges the bill carried, at what they came to.
     *
     * @param  list<PricedCharge>  $charges
     */
    private function copyCharges(Order $order, array $charges): void
    {
        if ($charges === []) {
            return;
        }

        $named = $this->named(Charge::query(), array_column($charges, 'id'), ['id', 'name', 'hsn_sac_code']);

        foreach ($charges as $position => $charge) {
            $orderCharge = new OrderCharge([
                'charge_id' => $charge['id'],
                'name' => $named->get($charge['id'])?->getTranslations('name') ?? [Locale::default()->value => $charge['name']],
                'amount' => $charge['amount'],
                'tax_rate' => $charge['taxParts']->rate(),
                'taxable_value' => $charge['taxableValue'],
                ...$charge['taxParts']->columns(),
                // Copied, because the charge may be recoded or deleted later.
                'hsn_sac_code' => $named->get($charge['id'])?->hsn_sac_code,
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
