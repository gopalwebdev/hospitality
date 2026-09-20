<?php

namespace App\Actions\Inventory;

use App\Actions\Baskets\PriceBasket;
use App\Models\MenuAddOnOption;
use App\Models\MenuComboItem;
use App\Models\MenuItem;

/**
 * What a basket takes from stock, row by row.
 *
 * An item line takes its quantity; a combo line takes each of its contents that
 * many times over, because a combo has no count of its own; every option picked
 * takes its quantity once per item on the line. A row several lines ask for is
 * one change, because two lines of one curry draw on one count. Rows nobody
 * counts are asked for too — ApplyStockChanges and FindStockShortages are what
 * know which rows are counted, and skip the rest.
 *
 * @phpstan-import-type BasketLine from PriceBasket
 */
final class StockDemand
{
    /**
     * @param  list<BasketLine>  $lines
     * @return list<StockChange> items first, then options
     */
    public function __invoke(array $lines): array
    {
        $contents = $this->comboContents($lines);

        /** @var array<int, array{quantity: int, lineKeys: list<string>}> $items */
        $items = [];

        /** @var array<int, array{quantity: int, lineKeys: list<string>}> $options */
        $options = [];

        foreach ($lines as $line) {
            $quantity = (int) $line['quantity'];

            if ($line['type'] === PriceBasket::COMBO) {
                foreach ($contents[(int) $line['id']] ?? [] as $itemId => $each) {
                    $this->ask($items, $itemId, $each * $quantity, $line['key']);
                }

                continue;
            }

            $this->ask($items, (int) $line['id'], $quantity, $line['key']);

            foreach ($line['choices'] ?? [] as $choice) {
                $this->ask($options, (int) $choice['optionId'], (int) $choice['quantity'] * $quantity, $line['key']);
            }
        }

        $changes = [];

        foreach ($items as $id => $demand) {
            $changes[] = StockChange::take(MenuItem::class, $id, $demand['quantity'], $demand['lineKeys']);
        }

        foreach ($options as $id => $demand) {
            $changes[] = StockChange::take(MenuAddOnOption::class, $id, $demand['quantity'], $demand['lineKeys']);
        }

        return $changes;
    }

    /**
     * How many of each item one of each combo holds, in one query for every combo line.
     *
     * @param  list<BasketLine>  $lines
     * @return array<int, array<int, int>> by combo, then by item
     */
    private function comboContents(array $lines): array
    {
        $comboIds = array_values(array_unique(array_map(
            static fn (array $line): int => (int) $line['id'],
            array_filter($lines, static fn (array $line): bool => $line['type'] === PriceBasket::COMBO),
        )));

        if ($comboIds === []) {
            return [];
        }

        $contents = [];

        foreach (MenuComboItem::query()->whereIn('menu_combo_id', $comboIds)->get(['id', 'menu_combo_id', 'menu_item_id', 'quantity']) as $content) {
            $contents[$content->menu_combo_id][$content->menu_item_id] = ($contents[$content->menu_combo_id][$content->menu_item_id] ?? 0) + $content->quantity;
        }

        return $contents;
    }

    /**
     * Add a line's ask to a row's running total.
     *
     * @param  array<int, array{quantity: int, lineKeys: list<string>}>  $demand
     */
    private function ask(array &$demand, int $id, int $quantity, string $lineKey): void
    {
        $demand[$id] ??= ['quantity' => 0, 'lineKeys' => []];
        $demand[$id]['quantity'] += $quantity;

        if (! in_array($lineKey, $demand[$id]['lineKeys'], true)) {
            $demand[$id]['lineKeys'][] = $lineKey;
        }
    }
}
