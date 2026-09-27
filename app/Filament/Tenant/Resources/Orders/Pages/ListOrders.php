<?php

namespace App\Filament\Tenant\Resources\Orders\Pages;

use App\Actions\Orders\ReadFloor;
use App\Enums\LocationKind;
use App\Filament\Tenant\Pages\TakeOrder;
use App\Filament\Tenant\Resources\Locations\LocationResource;
use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Models\Location;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
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

    public const string PLACES = 'places';

    /** How often the places layout asks again, in seconds. */
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
    private ?array $places = null;

    /**
     * The switcher, then whichever way the page is being read.
     */
    #[\Override]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.tenant.resources.orders.pages.layout-switcher'),

            $this->isPlaces()
                ? View::make('filament.tenant.resources.orders.pages.places')
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

    public function isPlaces(): bool
    {
        return $this->layoutMode === self::PLACES;
    }

    public function showLayout(string $layout): void
    {
        $this->layoutMode = $layout === self::PLACES ? self::PLACES : self::LIST;
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

        return array_values(array_filter($this->places(), function (array $card) use ($search): bool {
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
     * The whole floor in one line: how much is waiting, being made and ready.
     *
     * **No money**, on the project owner's instruction — a bill is the list
     * layout's business and the Locations page's Settle. What this answers is
     * what a shift lead wants at a glance: is anything going cold?
     *
     * @return array{pending: int, preparing: int, ready: int, locations: int}
     */
    public function summary(): array
    {
        $open = array_filter($this->places(), static fn (array $card): bool => $card['activity']['state']->isOpen());

        $sum = static fn (string $key): int => array_sum(array_map(
            static fn (array $card): int => $card['activity'][$key],
            $open,
        ));

        return [
            'pending' => $sum('pending'),
            'preparing' => $sum('preparing'),
            'ready' => $sum('ready'),
            'locations' => count($open),
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

    public function hasAnyLocation(): bool
    {
        return $this->places() !== [];
    }

    public function locationsUrl(): string
    {
        return LocationResource::getUrl('index');
    }

    /**
     * Where a card goes when it is pressed: the counter, at that place.
     *
     * The whole card is the link. It used to carry a "Take order" button and
     * an icon button crossing to the list with that location filtered, and
     * the project owner had both taken off — so this is now the one thing
     * pressing a card does. Nothing is lost by it: the counter lists what is
     * already running there, moves each of those orders along, changes one
     * the kitchen has not accepted, and links on to the full list
     * (`TakeOrder::ordersHereUrl()`).
     */
    public function locationUrl(Location $location): string
    {
        return TakeOrder::getUrl().'?location='.$location->getKey();
    }

    /**
     * The floor, read once per request however many times the view asks.
     *
     * @return list<array{location: Location, activity: array<string, mixed>}>
     */
    private function places(): array
    {
        return $this->places ??= app(ReadFloor::class)($this->tenant());
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
