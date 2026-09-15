<?php

namespace App\Actions\Menus;

use App\Actions\Inventory\FindStockShortages;
use App\Actions\Inventory\StockDemand;
use App\Enums\ItemAvailability;
use App\Exceptions\InsufficientStock;
use App\Models\Charge;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\MenuCombo;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Price a guest's basket against what one menu offers right now.
 *
 * The basket lives on the guest's phone, so everything in it is a claim: an item
 * may have sold out since it was added, a group's rules may have changed, an
 * option may be gone. Each line is read again here and priced only if it still
 * stands; one that does not comes back flagged rather than failing the basket, so
 * the guest can fix that line and keep the rest.
 *
 * The arithmetic is here rather than in the browser because it is a decision:
 * which rate each part is taxed at, whether prices already include GST, which
 * charges a bill from this menu carries. The app formats what this returns.
 * Placing an order (App\Actions\Orders\PlaceOrder) prices the basket here first,
 * and refuses it when a line is not `ok`.
 *
 * `shortages` is what the lines that stand would take from a counted item or
 * option with fewer left — a reading taken without a lock, so the guest can be
 * told before they order. A line keeps its status either way: an item with none
 * left is already out of stock, and so already `unavailable`.
 *
 * @phpstan-type BasketLine array{key: string, type: string, id: int, quantity: int, choices?: list<array{optionId: int, quantity: int}>}
 * @phpstan-type PricedPart array{amount: int, rate: int}
 *
 * @phpstan-import-type Shortage from InsufficientStock
 */
class QuoteBasket
{
    public const string ITEM = 'item';

    public const string COMBO = 'combo';

    public const string OK = 'ok';

    /** Sold out, hidden, or no longer on this menu. */
    public const string UNAVAILABLE = 'unavailable';

    /** Still on the menu, but its choices break the rules of its groups, or the basket holds more of it than one order may. */
    public const string INVALID = 'invalid';

    public function __construct(
        private readonly StockDemand $stockDemand,
        private readonly FindStockShortages $findStockShortages,
    ) {}

    /**
     * @param  list<BasketLine>  $lines
     * @return array{lines: list<array{key: string, status: string, unitPriceMinorUnits: int, totalMinorUnits: int}>, subtotalMinorUnits: int, taxMinorUnits: int, pricesIncludeTax: bool, charges: list<array{id: int, name: string, amountMinorUnits: int}>, totalMinorUnits: int, shortages: list<Shortage>}
     */
    public function __invoke(Tenant $tenant, Menu $menu, array $lines): array
    {
        $tenantRate = $tenant->taxRateBasisPoints();
        $settings = $tenant->resolvedSettings();
        $pricesIncludeTax = $settings instanceof TenantSetting && $settings->prices_include_tax;

        $items = $this->items($tenant, $menu, $this->idsOf($lines, self::ITEM));
        $groups = $this->groups($tenant, $items);
        $combos = $this->combos($menu, $this->idsOf($lines, self::COMBO));
        $held = $this->heldIn($lines);

        $priced = [];
        $subtotal = 0;
        $tax = 0;

        foreach ($lines as $line) {
            $parts = $line['type'] === self::COMBO
                ? $this->comboParts($combos->get($line['id']), $held[self::COMBO][$line['id']], $tenantRate)
                : $this->itemParts($items->get($line['id']), $groups, $line['choices'] ?? [], $held[self::ITEM][$line['id']], $tenantRate);

            if (is_string($parts)) {
                $priced[] = ['key' => $line['key'], 'status' => $parts, 'unitPriceMinorUnits' => 0, 'totalMinorUnits' => 0];

                continue;
            }

            $unit = array_sum(array_column($parts, 'amount'));
            $lineTotal = $unit * $line['quantity'];

            // Taxed part by part, and rounded once for the whole line rather
            // than per unit.
            foreach ($parts as $part) {
                $tax += $this->taxOn($part['amount'] * $line['quantity'], $part['rate'], $pricesIncludeTax);
            }

            $subtotal += $lineTotal;
            $priced[] = ['key' => $line['key'], 'status' => self::OK, 'unitPriceMinorUnits' => $unit, 'totalMinorUnits' => $lineTotal];
        }

        $charges = $this->charges($tenant, $menu, $subtotal);

        return [
            'lines' => $priced,
            'subtotalMinorUnits' => $subtotal,
            'taxMinorUnits' => $tax,
            'pricesIncludeTax' => $pricesIncludeTax,
            'charges' => $charges,
            'totalMinorUnits' => $subtotal
                + ($pricesIncludeTax ? 0 : $tax)
                + array_sum(array_column($charges, 'amountMinorUnits')),
            'shortages' => $this->shortagesIn($lines, $priced),
        ];
    }

    /**
     * What the lines that stand would take from a counted row with fewer left.
     *
     * @param  list<BasketLine>  $lines
     * @param  list<array{key: string, status: string, unitPriceMinorUnits: int, totalMinorUnits: int}>  $priced  in the same order as the lines
     * @return list<Shortage>
     */
    private function shortagesIn(array $lines, array $priced): array
    {
        $standing = [];

        foreach ($lines as $index => $line) {
            if ($priced[$index]['status'] === self::OK) {
                $standing[] = $line;
            }
        }

        return $standing === [] ? [] : ($this->findStockShortages)(($this->stockDemand)($standing));
    }

    /**
     * What one of an item costs with its choices, part by part, or why it cannot be had.
     *
     * @param  EloquentCollection<int, MenuAddOnGroup>  $groups
     * @param  list<array{optionId: int, quantity: int}>  $choices
     * @return list<PricedPart>|string
     */
    private function itemParts(?MenuItem $item, EloquentCollection $groups, array $choices, int $held, int $tenantRate): array|string
    {
        if (! $item instanceof MenuItem) {
            return self::UNAVAILABLE;
        }

        $offered = $item->addOnGroupLinks
            ->map(fn (MenuItemAddOnGroup $link): ?MenuAddOnGroup => $groups->get($link->menu_add_on_group_id))
            ->filter()
            ->keyBy(fn (MenuAddOnGroup $group): int => $group->getKey());

        // This item's own cap on each group's picks, tighter or looser than
        // the group's own maximum; a group with no link of its own (there
        // should always be one) falls back to the group's maximum.
        $maxSelections = $offered->map(function (MenuAddOnGroup $group) use ($item): ?int {
            $link = $item->addOnGroupLinks->firstWhere('menu_add_on_group_id', $group->getKey());

            return $link instanceof MenuItemAddOnGroup ? $link->effectiveMaxSelections($group) : $group->max_selections;
        });

        // The decision the menu screen makes: a required group that its
        // available options can no longer meet takes the item off the menu.
        if ($offered->contains(fn (MenuAddOnGroup $group): bool => ! $group->canBeMetBy($group->picksOffered($maxSelections->get($group->getKey()))))) {
            return self::UNAVAILABLE;
        }

        // However it was chosen, one order holds only as many of an item as the
        // tenant allows, counted across every line it is on.
        if (! $this->isWithinLimits($item, $held)) {
            return self::INVALID;
        }

        $options = $offered
            ->flatMap(fn (MenuAddOnGroup $group): array => $group->options->all())
            ->keyBy(fn (MenuAddOnOption $option): int => $option->getKey());

        $quantities = [];

        foreach ($choices as $choice) {
            $quantities[$choice['optionId']] = ($quantities[$choice['optionId']] ?? 0) + $choice['quantity'];
        }

        // Every part of the line is taxed at the item's rate. An add-on is part of
        // the item it is added to — a composite supply, taxed at the rate of its
        // principal supply (CGST Act, s. 8(a)) — so an option has no rate of its own.
        $rate = $item->taxRateBasisPoints($tenantRate);
        $parts = [['amount' => $item->price_minor_units, 'rate' => $rate]];
        $picks = [];

        foreach ($quantities as $optionId => $quantity) {
            $option = $options->get($optionId);

            // Not an available option of one of this item's groups.
            if (! $option instanceof MenuAddOnOption) {
                return self::INVALID;
            }

            $group = $offered->get($option->menu_add_on_group_id);

            // Or more of it than its group — or this item's own cap on it —
            // lets a guest take.
            if (! $group instanceof MenuAddOnGroup || $quantity > $group->quantityAllowedFor($option, $maxSelections->get($group->getKey()))) {
                return self::INVALID;
            }

            $picks[$group->getKey()] = ($picks[$group->getKey()] ?? 0) + $quantity;
            $parts[] = ['amount' => $option->price_minor_units * $quantity, 'rate' => $rate];
        }

        foreach ($offered as $group) {
            $count = $picks[$group->getKey()] ?? 0;
            $max = $maxSelections->get($group->getKey());

            if (($group->is_required && $count === 0) || ($max !== null && $count > $max)) {
                return self::INVALID;
            }
        }

        return $parts;
    }

    /**
     * What one of a combo costs, or why it cannot be had.
     *
     * @return list<PricedPart>|string
     */
    private function comboParts(?MenuCombo $combo, int $held, int $tenantRate): array|string
    {
        if (! $combo instanceof MenuCombo) {
            return self::UNAVAILABLE;
        }

        if (! $this->isWithinLimits($combo, $held)) {
            return self::INVALID;
        }

        return [['amount' => $combo->price_minor_units, 'rate' => $combo->taxRateBasisPoints($tenantRate)]];
    }

    /**
     * Whether the basket holds no more of an item or a combo than one order may.
     */
    private function isWithinLimits(MenuItem|MenuCombo $thing, int $held): bool
    {
        return $thing->max_quantity === null || $held <= $thing->max_quantity;
    }

    /**
     * How many of each item and each combo the basket holds, across every line it is on.
     *
     * @param  list<BasketLine>  $lines
     * @return array<string, array<int, int>> by line type, then by id
     */
    private function heldIn(array $lines): array
    {
        $held = [];

        foreach ($lines as $line) {
            $held[$line['type']][$line['id']] = ($held[$line['type']][$line['id']] ?? 0) + $line['quantity'];
        }

        return $held;
    }

    /**
     * The GST on an amount: added on top, or the share already inside it.
     */
    private function taxOn(int $amount, int $rate, bool $included): int
    {
        $whole = TenantSetting::BASIS_POINTS_PER_WHOLE;

        return (int) round($included ? $amount * $rate / ($whole + $rate) : $amount * $rate / $whole);
    }

    /**
     * The items asked for that a guest can order from this menu right now, with the groups each offers.
     *
     * @param  list<int>  $ids
     * @return EloquentCollection<int, MenuItem>
     */
    private function items(Tenant $tenant, Menu $menu, array $ids): EloquentCollection
    {
        if ($ids === []) {
            return new EloquentCollection;
        }

        return MenuItem::query()
            ->select(['id', 'tenant_id', 'price_minor_units', 'tax_rate_basis_points', 'max_quantity'])
            ->where('tenant_id', $tenant->getKey())
            ->onMenu($menu->getKey())
            ->orderable()
            ->whereKey($ids)
            ->with(['addOnGroupLinks' => fn ($links) => $links->select(['id', 'menu_item_id', 'menu_add_on_group_id', 'max_selections'])])
            ->get()
            ->keyBy(fn (MenuItem $item): int => $item->getKey());
    }

    /**
     * The groups those items offer, with only the options a guest can have right now.
     *
     * @param  EloquentCollection<int, MenuItem>  $items
     * @return EloquentCollection<int, MenuAddOnGroup>
     */
    private function groups(Tenant $tenant, EloquentCollection $items): EloquentCollection
    {
        $ids = $items
            ->flatMap(fn (MenuItem $item): array => $item->addOnGroupLinks->pluck('menu_add_on_group_id')->all())
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return new EloquentCollection;
        }

        return MenuAddOnGroup::query()
            ->select(['id', 'tenant_id', 'is_required', 'max_selections'])
            ->where('tenant_id', $tenant->getKey())
            ->whereKey($ids)
            ->with(['options' => fn ($options) => $options
                ->select(['id', 'tenant_id', 'menu_add_on_group_id', 'price_minor_units', 'max_quantity'])
                ->available()])
            ->get()
            ->keyBy(fn (MenuAddOnGroup $group): int => $group->getKey());
    }

    /**
     * The combos asked for that are still offered on this menu.
     *
     * @param  list<int>  $ids
     * @return EloquentCollection<int, MenuCombo>
     */
    private function combos(Menu $menu, array $ids): EloquentCollection
    {
        if ($ids === []) {
            return new EloquentCollection;
        }

        return MenuCombo::query()
            ->select(['id', 'tenant_id', 'price_minor_units', 'tax_rate_basis_points', 'max_quantity'])
            ->where('menu_id', $menu->getKey())
            ->whereIn('availability', ItemAvailability::orderableValues())
            ->whereKey($ids)
            ->get()
            ->keyBy(fn (MenuCombo $combo): int => $combo->getKey());
    }

    /**
     * What a bill from this menu adds on a subtotal, charge by charge, in the tenant's order.
     *
     * Nothing is charged on nothing: an empty basket carries no fixed fee.
     *
     * @return list<array{id: int, name: string, amountMinorUnits: int}>
     */
    private function charges(Tenant $tenant, Menu $menu, int $subtotal): array
    {
        if ($subtotal === 0) {
            return [];
        }

        return array_values(Charge::query()
            ->select(['id', 'name', 'calculation', 'rate_basis_points', 'amount_minor_units'])
            ->where('tenant_id', $tenant->getKey())
            ->active()
            ->forMenu($menu->getKey())
            ->inMenuOrder()
            ->get()
            ->map(fn (Charge $charge): array => [
                'id' => $charge->getKey(),
                'name' => $charge->name,
                'amountMinorUnits' => $charge->amountOn($subtotal),
            ])
            ->all());
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
}
