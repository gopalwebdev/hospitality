<?php

namespace App\Filament\Tenant\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Tenant\Pages\TakeOrder;
use App\Filament\Tenant\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every order placed, newest first, cut by what state it is in.
 *
 * **There is one way to read this page.** It had two — the table, and a
 * "Places" layout drawing a card per room with what was open at each, switched
 * by a tabs strip above them — and the project owner had the whole Places
 * module removed: a board of rooms answered a question the table answers with
 * a filter, and it cost a second layout, two actions, an enum, two Blade
 * partials and a poll every ten seconds to do it. The tabs strip went with it,
 * and the tabs here are the resource's own, over status.
 *
 * What was lost with it, deliberately: a live view of the floor. Nothing polls
 * any more, and "what is happening at Room 204" is now the place filter.
 */
class ListOrders extends ListRecords
{
    #[\Override]
    protected static string $resource = OrderResource::class;

    /**
     * Take one here, rather than waiting for a guest's phone.
     *
     * A link to the counter (App\Filament\Tenant\Pages\TakeOrder) rather than a
     * CreateAction: an order is a basket priced against a menu, not a form, and
     * OrderPolicy still refuses editing and deleting what has been placed. It
     * is hidden from someone who may only read orders, which is what
     * `create` answers.
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('takeOrder')
                ->label(__('panel.take_order.new'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => TakeOrder::getUrl())
                ->visible(fn (): bool => TakeOrder::canAccess()),
        ];
    }

    /**
     * One tab per state an order can be in, with how many are in it.
     *
     * The project owner asked for the cuts staff make all shift to be one
     * press rather than a filter to open and set. A tab combines with the
     * filters below it — the list opens on today, so these count today's
     * orders too, and the badges say what the tab will show rather than what
     * the whole table holds.
     *
     * Every badge comes from one query of conditional counts, deferred until
     * the page has rendered, the same way the items page does it.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make(__('panel.orders.all_tab'))
                ->icon(Heroicon::OutlinedRectangleStack)
                ->badge(fn (): int => $this->counts()['all'])
                ->deferBadge(),
        ];

        foreach (OrderStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->icon($status->icon())
                ->badge(fn (): int => $this->counts()[$status->value])
                ->badgeColor($status->color())
                ->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('orders.status', $status->value));
        }

        return $tabs;
    }

    /**
     * How many orders each tab holds, counted the same way the tab filters.
     *
     * One query of conditional counts for every badge rather than one per
     * tab. It runs through the page's own table query, so **whatever is
     * filtered above stays filtered in the counts** — a badge saying 40 over
     * a list of 3 is worse than no badge. The panel's tenancy scope rides
     * along with it.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        return once(function (): array {
            // The resource's own query — tenant-scoped, and **without the
            // active tab**. `getFilteredTableQuery()` would carry the tab
            // (ListRecords applies it as the table's `modifyQueryUsing`), so
            // every badge would show the tab already being read. This asks
            // for the filters alone, which is what keeps a badge and the
            // list underneath it agreeing about the day.
            $query = static::getResource()::getEloquentQuery();

            $this->filterTableQuery($query);

            // Grouped rather than a conditional sum per case: one query
            // either way, and a status added later is counted without a line
            // of SQL being written for it.
            $totals = $query
                ->reorder()
                ->toBase()
                ->select('orders.status')
                ->selectRaw('count(*) as total')
                ->groupBy('orders.status')
                ->pluck('total', 'status');

            $counts = ['all' => (int) $totals->sum()];

            foreach (OrderStatus::cases() as $status) {
                $counts[$status->value] = (int) $totals->get($status->value, 0);
            }

            return $counts;
        });
    }
}
