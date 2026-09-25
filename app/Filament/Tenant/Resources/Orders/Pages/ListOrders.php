<?php

namespace App\Filament\Tenant\Resources\Orders\Pages;

use App\Actions\Orders\ReadFloor;
use App\Actions\Orders\ReadLocationActivity;
use App\Enums\Currency;
use App\Enums\LocationKind;
use App\Filament\Schemas\PricingFields;
use App\Filament\Tenant\Pages\TakeOrder;
use App\Filament\Tenant\Resources\Locations\LocationResource;
use App\Filament\Tenant\Resources\Locations\Tables\LocationsTable;
use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Models\Location;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use LogicException;

/**
 * Orders, read two ways: as a list, and as the floor.
 *
 * The project owner asked for both on this one page rather than a page each.
 * They answer different questions and staff switch between them all shift:
 *
 * - **List** — the table. Every order, filterable and searchable, newest first.
 *   What was ordered, by whom, what it came to, what has been paid.
 * - **Floor** — a card per room, table and delivery point, with what is open at
 *   each. Where the work is, rather than what the work was.
 *
 * The floor was its own OrderBoard page for one revision and was folded in
 * here, because two navigation entries for one subject is one too many and
 * "show me the floor" is a way of looking at orders, not a different thing.
 *
 * **The floor is live by polling, not by push.** `wire:poll` on the grid, one
 * `ReadFloor` read a tick (two queries) however many cards are drawn. There is
 * no Reverb and no Echo in this project, and neither was added for a screen
 * that is looked at rather than typed into.
 *
 * Nothing on the floor is stored: `App\Enums\LocationActivity` is worked out
 * from each location's open orders every time, and the cards are sorted so the
 * ones with something owing come first (`ReadFloor`).
 */
class ListOrders extends ListRecords
{
    #[\Override]
    protected static string $resource = OrderResource::class;

    public const string LIST = 'list';

    public const string FLOOR = 'floor';

    /** How often the floor asks again, in seconds. */
    private const int POLL_SECONDS = 10;

    /**
     * Which of the two ways this page is being read.
     *
     * In the query string, so the two are two addresses rather than two
     * states of one: opening an order from the floor and coming back lands
     * on the floor, and the browser's own back button agrees with the tabs.
     *
     * Not `$layout`: `Filament\Pages\Page` already declares a **static**
     * `$layout` (the Blade layout a page renders into), and a non-static
     * property of that name is a fatal error, not a shadowing.
     */
    #[Url(as: 'view')]
    public string $layoutMode = self::LIST;

    /** The floor's own filters; the list has the table's. */
    #[Url(as: 'kind')]
    public ?string $kind = null;

    public string $floorSearch = '';

    public bool $onlyOpen = false;

    /** @var list<array{location: Location, activity: array<string, mixed>}>|null */
    private ?array $floor = null;

    /**
     * The switcher, then whichever way the page is being read.
     */
    #[\Override]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.tenant.resources.orders.pages.layout-switcher'),

            $this->isFloor()
                ? View::make('filament.tenant.resources.orders.pages.floor')
                : EmbeddedTable::make(),
        ]);
    }

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

    public function isFloor(): bool
    {
        return $this->layoutMode === self::FLOOR;
    }

    public function showLayout(string $layout): void
    {
        $this->layoutMode = $layout === self::FLOOR ? self::FLOOR : self::LIST;
    }

    /**
     * What the poll interval reads as in the view: "10s".
     */
    public function pollInterval(): string
    {
        return self::POLL_SECONDS.'s';
    }

    /**
     * The cards to draw: the floor, narrowed by the three controls above it.
     *
     * Filtered here rather than in ReadFloor because the counter's picker
     * narrows the same list differently, and it is already in memory.
     *
     * @return list<array{location: Location, activity: array<string, mixed>}>
     */
    public function cards(): array
    {
        $search = trim($this->floorSearch);

        return array_values(array_filter($this->floor(), function (array $card) use ($search): bool {
            if ($this->kind !== null && $card['location']->kind->value !== $this->kind) {
                return false;
            }

            if ($this->onlyOpen && ! $card['activity']['state']->isOpen()) {
                return false;
            }

            return $search === '' || $this->matches($card['location'], $search);
        }));
    }

    /**
     * The whole floor in one line: how many locations have something open, how
     * many orders that is, and what they owe.
     *
     * @return array{locations: int, openOrders: int, outstanding: int}
     */
    public function summary(): array
    {
        $open = array_filter($this->floor(), static fn (array $card): bool => $card['activity']['state']->isOpen());

        return [
            'locations' => count($open),
            'openOrders' => array_sum(array_map(static fn (array $card): int => $card['activity']['openOrders'], $open)),
            'outstanding' => array_sum(array_map(static fn (array $card): int => $card['activity']['outstanding'], $open)),
        ];
    }

    /**
     * The kinds this tenant is offered, which is what its type decides —
     * never LocationKind::cases(), which drew a hotel a permanently empty
     * "Table" tab (`.ai/rules/locations.md`).
     *
     * @return list<LocationKind>
     */
    public function kinds(): array
    {
        return $this->tenant()->type->locationKinds();
    }

    public function currency(): Currency
    {
        return PricingFields::currency();
    }

    public function hasAnyLocation(): bool
    {
        return $this->floor() !== [];
    }

    public function locationsUrl(): string
    {
        return LocationResource::getUrl('index');
    }

    /**
     * The counter, with this location already chosen.
     */
    public function takeOrderUrl(Location $location): string
    {
        return TakeOrder::getUrl().'?location='.$location->getKey();
    }

    /**
     * Swap to the list, showing only this location's orders.
     *
     * The two ways of reading the page are joined here: a card is a question
     * ("what is going on at 204?") and the list is the answer, so the filter
     * is set on the way across rather than left for staff to find.
     */
    public function showOrdersAt(int $locationId): void
    {
        $this->layoutMode = self::LIST;
        $this->tableFilters ??= [];
        $this->tableFilters['location_id']['value'] = (string) $locationId;

        $this->resetPage();
    }

    /**
     * Check a location out without leaving the floor.
     *
     * The Locations page's own action, handed the card's location through the
     * arguments it was invoked with rather than a row — one definition of what
     * settling asks for and does, in both places. Its visibility is overridden
     * because the table's own runs a query per row to find out whether anything
     * is outstanding, and the floor already knows: a card per room would
     * otherwise be a query per room on every poll.
     */
    public function settleAction(): Action
    {
        return LocationsTable::settleAction()
            ->size(Size::Small)
            ->record(fn (array $arguments): ?Location => $this->locationFrom($arguments))
            ->visible(fn (array $arguments): bool => $this->activityFor($arguments)['outstanding'] > 0);
    }

    /**
     * The floor, read once per request however many times the view asks.
     *
     * @return list<array{location: Location, activity: array<string, mixed>}>
     */
    private function floor(): array
    {
        return $this->floor ??= app(ReadFloor::class)($this->tenant());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function locationFrom(array $arguments): ?Location
    {
        $id = (int) ($arguments['location'] ?? 0);

        foreach ($this->floor() as $card) {
            if ($card['location']->getKey() === $id) {
                return $card['location'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function activityFor(array $arguments): array
    {
        $id = (int) ($arguments['location'] ?? 0);

        foreach ($this->floor() as $card) {
            if ($card['location']->getKey() === $id) {
                return $card['activity'];
            }
        }

        return ReadLocationActivity::clear();
    }

    /**
     * Matched on the name a guest reads and on the shorthand staff type — "204" finds Room 204.
     */
    private function matches(Location $location, string $search): bool
    {
        return mb_stripos($location->name, $search) !== false
            || (filled($location->code) && mb_stripos((string) $location->code, $search) !== false);
    }

    private function tenant(): Tenant
    {
        $tenant = Filament::getTenant();

        throw_unless($tenant instanceof Tenant, LogicException::class, 'The orders page requires a tenant.');

        return $tenant;
    }
}
