<?php

namespace App\Actions\Inventory;

use App\Exceptions\InsufficientStock;
use App\Models\MenuAddOnOption;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which takes would come up short right now, without locking anything.
 *
 * What a priced basket shows before a guest places the order. It is a reading, not a
 * promise: two guests can both see enough left, and the one who orders second
 * is refused by ApplyStockChanges under its lock.
 *
 * @phpstan-import-type Shortage from InsufficientStock
 */
final class FindStockShortages
{
    /**
     * @param  list<StockChange>  $takes
     * @return list<Shortage>
     */
    public function __invoke(array $takes): array
    {
        $left = [
            MenuItem::class => $this->counted(MenuItem::query(), $takes, MenuItem::class),
            MenuAddOnOption::class => $this->counted(MenuAddOnOption::query(), $takes, MenuAddOnOption::class),
        ];

        $shortages = [];

        foreach ($takes as $take) {
            $available = $left[$take->type][$take->id] ?? null;

            if ($available !== null && (int) $take->quantity > $available) {
                $shortages[] = InsufficientStock::shortage($take, $available);
            }
        }

        return $shortages;
    }

    /**
     * How many each counted row of one kind has left, by key; rows nobody counts are absent.
     *
     * @template TRow of MenuItem|MenuAddOnOption
     *
     * @param  Builder<TRow>  $query
     * @param  list<StockChange>  $takes
     * @param  class-string<TRow>  $type
     * @return array<int, int>
     */
    private function counted(Builder $query, array $takes, string $type): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (StockChange $take): int => $take->id,
            array_filter($takes, static fn (StockChange $take): bool => $take->type === $type),
        )));

        if ($ids === []) {
            return [];
        }

        return array_map(
            intval(...),
            $query->whereKey($ids)->whereNotNull('stock_quantity')->pluck('stock_quantity', 'id')->all(),
        );
    }
}
