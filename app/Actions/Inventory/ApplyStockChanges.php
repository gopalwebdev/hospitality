<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementReason;
use App\Exceptions\InsufficientStock;
use App\Models\MenuAddOnOption;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Change counts under a lock, all of them or none.
 *
 * The one place an existing item's or option's count is written: an order taking
 * stock (PlaceOrder), a cancelled one putting it back (CancelOrder), and an admin
 * restocking or counting in the panel. Every row named is read again with
 * SELECT … FOR UPDATE — items before options, each in key order — so two orders
 * placed at once queue on the same rows in the same order rather than
 * deadlocking, and neither can spend what the other already has.
 *
 * A take asking a counted row for more than it has left refuses the whole set
 * and says what every short row has (InsufficientStock); nothing is written. A
 * row nobody counts is never short and never changed by a take or an add.
 *
 * Saving the row is what marks an item out of stock at none left, and available
 * again when stock arrives (MenuItemObserver). An option with none left is simply
 * not offered (MenuAddOnOption::scopeAvailable()).
 */
final readonly class ApplyStockChanges
{
    public function __construct(private RecordStockMovement $recordStockMovement) {}

    /**
     * @param  list<StockChange>  $changes
     *
     * @throws InsufficientStock when a take asks a counted row for more than it has left
     */
    public function __invoke(
        array $changes,
        StockMovementReason $reason,
        ?Order $order = null,
        ?User $user = null,
        ?string $note = null,
    ): void {
        if ($changes === []) {
            return;
        }

        DB::transaction(function () use ($changes, $reason, $order, $user, $note): void {
            $rows = [
                MenuItem::class => $this->lock(MenuItem::query(), $changes, MenuItem::class, ['id', 'tenant_id', 'stock_quantity', 'availability']),
                MenuAddOnOption::class => $this->lock(MenuAddOnOption::query(), $changes, MenuAddOnOption::class, ['id', 'tenant_id', 'stock_quantity']),
            ];

            /** @var array<class-string<MenuItem|MenuAddOnOption>, array<int, int|null>> $counts */
            $counts = [];
            $shortages = [];

            foreach ($changes as $change) {
                $row = $rows[$change->type]->get($change->id);

                // Deleted since it was asked for: there is nothing left to count.
                if ($row === null) {
                    continue;
                }

                $current = array_key_exists($change->id, $counts[$change->type] ?? [])
                    ? $counts[$change->type][$change->id]
                    : $row->stock_quantity;

                $next = $change->applyTo($current);

                if ($change->isTake() && $current !== null && $next < 0) {
                    $shortages[] = InsufficientStock::shortage($change, $current);

                    continue;
                }

                $counts[$change->type][$change->id] = $next;
            }

            throw_if($shortages !== [], InsufficientStock::class, $shortages);

            foreach ($counts as $type => $byId) {
                foreach ($byId as $id => $count) {
                    $row = $rows[$type]->get($id);
                    $before = $row?->stock_quantity;

                    if ($row === null || $count === $before) {
                        continue;
                    }

                    $row->stock_quantity = $count;
                    $row->save();

                    // Tracking switched off leaves nothing to say how many are left.
                    if ($count !== null) {
                        ($this->recordStockMovement)($row, $count - (int) $before, $reason, $order, $user, $note);
                    }
                }
            }
        });
    }

    /**
     * The rows of one kind the changes name, locked until the transaction ends.
     *
     * Every tenant's rows are read: the keys come from a basket already priced
     * against this tenant's menu, or from a record the panel has already scoped.
     *
     * @template TRow of MenuItem|MenuAddOnOption
     *
     * @param  Builder<TRow>  $query
     * @param  list<StockChange>  $changes
     * @param  class-string<TRow>  $type
     * @param  list<string>  $columns
     * @return EloquentCollection<int, TRow>
     */
    private function lock(Builder $query, array $changes, string $type, array $columns): EloquentCollection
    {
        $ids = array_values(array_unique(array_map(
            static fn (StockChange $change): int => $change->id,
            array_filter($changes, static fn (StockChange $change): bool => $change->type === $type),
        )));

        if ($ids === []) {
            return new EloquentCollection;
        }

        sort($ids);

        return $query
            ->withoutGlobalScopes()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get($columns)
            ->keyBy(fn (MenuItem|MenuAddOnOption $row): int => $row->getKey());
    }
}
