<?php

namespace App\Filament\Tenant\Pages;

use App\Actions\Baskets\PriceBasket;
use App\Actions\Menus\ReadOrderableMenu;
use App\Actions\Orders\PlaceOrder;
use App\Actions\Orders\ReadFloor;
use App\Actions\Orders\ReadLocationActivity;
use App\Actions\Orders\ReviseOrder;
use App\Enums\Currency;
use App\Enums\OrderSettlement;
use App\Exceptions\InsufficientStock;
use App\Exceptions\OrderRefused;
use App\Filament\Schemas\PricingFields;
use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Filament\Tenant\Resources\Orders\Tables\OrdersTable;
use App\Models\Location;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\MenuCombo;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Url;
use LogicException;

/**
 * The counter: a menu on one side, a basket on the other, and one button that places the order.
 *
 * Staff take orders over the phone and at the desk, and until now the only way
 * an order existed was a guest's own phone. This is the same PlaceOrder the
 * guest API calls, reached from a floor card on the orders page and from its
 * page's New order button, with a location already chosen where it came from a
 * card.
 *
 * **Every figure on this page is PriceBasket's.** The basket is re-priced by
 * the very action the guest app posts to on every change to it, so a total read
 * out to a guest on the phone is the total their own phone would have shown —
 * the same per-line GST, the same charges, the same rounding. Nothing here adds
 * up money of its own; see `.ai/rules/actions-menus.md`.
 *
 * Two things this page may do that a guest's phone may not:
 * - **It orders past closing.** PlaceOrder is passed `allowOutsideHours: true`,
 *   on the project owner's decision, with a banner saying so. Every other
 *   refusal still stands: a sold-out line, a broken group, a stock shortage.
 * - **It names a location by hand.** A tenant with no locations set up, or an
 *   order going somewhere that is not a row, types where it goes.
 *
 * Deliberately not registered in the navigation: an order is taken *about*
 * somewhere, so it is reached from the board or from Orders rather than
 * started cold from a sidebar link.
 *
 * @property-read Schema $form
 *
 * @phpstan-import-type BasketLine from PriceBasket
 * @phpstan-import-type OrderableSection from ReadOrderableMenu
 */
class TakeOrder extends Page
{
    #[\Override]
    protected string $view = 'filament.tenant.pages.take-order';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The basket, in exactly the shape PriceBasket and PlaceOrder read, plus
     * the name each line was added under so the page can draw it without
     * looking the item up again.
     *
     * @var list<array{key: string, type: string, id: int, quantity: int, choices: list<array{optionId: int, quantity: int}>, name: string, choiceNames: list<string>}>
     */
    public array $lines = [];

    public string $search = '';

    /** The most of a location's running orders the page lists before it stops. */
    private const int OPEN_ORDERS_SHOWN = 8;

    /**
     * Where the order goes. Picked from the grid rather than typed into a
     * select: a tenant with fifty rooms is a fifty-row dropdown, and staff
     * need to see what is already open at a room before they add to it.
     *
     * In the query string, so the picker and the counter are two addresses
     * rather than two states of one: the browser's own back button takes a
     * member of staff from a room back to the grid, which is what they press.
     */
    #[Url(as: 'location')]
    public ?int $locationId = null;

    /**
     * The order being changed, when this is a change rather than a new order.
     *
     * Also in the query string, because the Change button on the orders page
     * is a link to it and going back from here has to land where it came from.
     */
    #[Url(as: 'order')]
    public ?int $orderId = null;

    /** Staff chose to name where it goes by hand instead of picking. */
    public bool $isElsewhere = false;

    public string $locationSearch = '';

    /** @var list<Menu>|null */
    private ?array $menus = null;

    /** @var array{sections: list<OrderableSection>, groups: EloquentCollection<int, MenuAddOnGroup>}|null */
    private ?array $catalogue = null;

    /** @var array<string, mixed>|null */
    private ?array $priced = null;

    /** @var list<array{location: Location, activity: array<string, mixed>}>|null */
    private ?array $floor = null;

    /** @var EloquentCollection<int, Order>|null */
    private ?EloquentCollection $openOrdersHere = null;

    private ?Order $changing = null;

    /**
     * Ordering is order.create, which staff hold as well as an owner. The
     * policy is asked rather than the permission directly, so this page and
     * the Orders resource cannot drift apart.
     */
    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->can('create', Order::class);
    }

    /**
     * Reached from a floor card or from the Orders page, never from a sidebar link.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $order = $this->orderBeingChanged();

        if ($order instanceof Order) {
            $this->fillFromOrder($order);

            return;
        }

        // Not changing anything: a new order. A floor card hands the location
        // over in the query string; arriving without one puts the page on its
        // picker instead.
        $this->orderId = null;
        $this->locationId = $this->locationNamed((int) $this->locationId) instanceof Location ? $this->locationId : null;

        $this->form->fill([
            'menu_id' => ($this->menus()[0] ?? null)?->getKey(),
            'settlement' => OrderSettlement::AddToBill->value,
        ]);
    }

    public function getTitle(): string
    {
        return (string) ($this->isChangingAnOrder()
            ? __('panel.take_order.change_title', ['number' => $this->orderId])
            : __('panel.take_order.title'));
    }

    public function getHeading(): string
    {
        $location = $this->location();

        if ($this->isChangingAnOrder()) {
            return (string) __('panel.take_order.change_title', ['number' => $this->orderId]);
        }

        return $location instanceof Location
            ? (string) __('panel.take_order.heading_at', ['name' => $location->name])
            : (string) __('panel.take_order.title');
    }

    /**
     * Whether this is a change to an order that already exists.
     */
    public function isChangingAnOrder(): bool
    {
        return $this->orderId !== null;
    }

    /**
     * Where Back goes, which is wherever this was reached from.
     *
     * Three cases and they are all one rule — go up one step: changing an
     * order came from the orders page, a room came from the picker, and the
     * picker itself came from the orders page. The location lives in the
     * query string too, so the browser's own back button agrees with this
     * button rather than fighting it.
     */
    public function backUrl(): string
    {
        if ($this->isChangingAnOrder()) {
            return OrderResource::getUrl('index');
        }

        return $this->isPickingLocation() || ! $this->hasAnyLocation()
            ? OrderResource::getUrl('index')
            : TakeOrder::getUrl();
    }

    /**
     * One icon button back, in the page's own header.
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('panel.take_order.back'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->iconButton()
                ->tooltip(__('panel.take_order.back'))
                ->url(fn (): string => $this->backUrl()),
        ];
    }

    /**
     * Where it goes, off which menu, and how it settles.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('panel.take_order.details'))
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->compact()
                    ->schema([
                        Select::make('menu_id')
                            ->label(__('panel.categories.menu'))
                            ->options(fn (): array => $this->menuOptions())
                            ->required()
                            ->native(false)
                            ->selectablePlaceholder(false)
                            // A basket is priced against one menu, so switching
                            // menus empties it rather than carrying lines onto
                            // a card that may not offer them.
                            ->live()
                            ->afterStateUpdated(fn (): null => $this->emptyBasket()),

                        // Where it goes is picked from the grid above, not
                        // here. This is the other path: a tenant that has set
                        // up no locations at all, and an order going somewhere
                        // that is not a row. A picked location wins over it,
                        // exactly as it does for a guest who typed rather than
                        // picked (PlaceOrder).
                        TextInput::make('location_label')
                            ->label(__('panel.take_order.location_label'))
                            ->maxLength(120)
                            ->visible(fn (): bool => $this->isElsewhere || ! $this->hasAnyLocation()),

                        Select::make('settlement')
                            ->label(__('panel.orders.settlement'))
                            ->options(OrderSettlement::options())
                            ->required()
                            ->native(false)
                            ->selectablePlaceholder(false),

                        Textarea::make('note')
                            ->label(__('panel.orders.note'))
                            ->maxLength(500)
                            ->rows(2),
                    ]),
            ]);
    }

    /**
     * Whether this tenant has anywhere to pick from at all.
     */
    public function hasAnyLocation(): bool
    {
        return $this->floor() !== [];
    }

    /**
     * Whether the page is still on its first question: where does this go?
     *
     * A tenant with no locations at all never asks it — there is nothing to
     * pick from, so where it goes is typed beside the order instead.
     */
    public function isPickingLocation(): bool
    {
        return $this->hasAnyLocation()
            && ! $this->isChangingAnOrder()
            && $this->locationId === null
            && ! $this->isElsewhere;
    }

    /**
     * Take the order to this room, table or delivery point.
     */
    public function chooseLocation(int $locationId): void
    {
        if ($this->locationNamed($locationId) === null) {
            return;
        }

        $this->locationId = $locationId;
        $this->isElsewhere = false;
        $this->data['location_label'] = null;
        $this->openOrdersHere = null;
    }

    /**
     * Somewhere that is not one of the tenant's rows: named by hand instead.
     */
    public function chooseElsewhere(): void
    {
        $this->locationId = null;
        $this->isElsewhere = true;
        $this->openOrdersHere = null;
    }

    /**
     * Back to the grid. The basket survives: a guest changing their mind about
     * the table has not changed their mind about the order.
     */
    public function changeLocation(): void
    {
        $this->locationId = null;
        $this->isElsewhere = false;
        $this->locationSearch = '';
        $this->openOrdersHere = null;
    }

    /**
     * Every location to pick from, each with what is open at it, narrowed to
     * whatever has been typed into the picker's search.
     *
     * The order is ReadFloor's, the same the orders page's floor layout uses:
     * rooms with something owing first, newest order at the top, everywhere
     * quiet last — so the one staff are most likely to be adding to is nearest
     * the search box. Matched on the name a guest reads and on the shorthand
     * staff type, so "204" finds Room 204.
     *
     * @return list<array{location: Location, activity: array<string, mixed>}>
     */
    public function locationCards(): array
    {
        $search = trim($this->locationSearch);

        if ($search === '') {
            return $this->floor();
        }

        return array_values(array_filter(
            $this->floor(),
            static fn (array $card): bool => mb_stripos($card['location']->name, $search) !== false
                || (filled($card['location']->code) && mb_stripos((string) $card['location']->code, $search) !== false),
        ));
    }

    /**
     * What is open at the location this order is going to.
     *
     * @return array<string, mixed>
     */
    public function activityHere(): array
    {
        foreach ($this->floor() as $card) {
            if ($card['location']->getKey() === $this->locationId) {
                return $card['activity'];
            }
        }

        return ReadLocationActivity::clear();
    }

    /**
     * The orders already running at this location, newest first — what staff
     * are looking at when they ask "what is going on at 204".
     *
     * Placed and still owing, the same pair the board counts and the Settle
     * action offers. Read once per request, and not at all while the picker
     * is up.
     *
     * @return EloquentCollection<int, Order>
     */
    public function openOrdersHere(): EloquentCollection
    {
        if ($this->openOrdersHere instanceof EloquentCollection) {
            return $this->openOrdersHere;
        }

        $location = $this->location();

        if (! $location instanceof Location) {
            return $this->openOrdersHere = new EloquentCollection;
        }

        // Every column, unusually: any of these rows can be opened in the
        // order modal, which reads the whole record — its totals, its GST
        // split, its note — and a row selected down to five columns throws on
        // the first one the infolist asks for (Model::shouldBeStrict()). The
        // list is capped at OPEN_ORDERS_SHOWN, so this is a handful of rows.
        return $this->openOrdersHere = Order::query()
            ->where('location_id', $location->getKey())
            ->live()
            ->unsettled()
            // Aliased exactly amount_paid and constrained to live payments,
            // the contract Order::amountPaid() reads it back under.
            ->withSum(['paymentAllocations as amount_paid' => fn ($allocations) => $allocations
                ->whereHas('payment', fn ($payment) => $payment->live())], 'amount')
            ->latest('id')
            ->limit(self::OPEN_ORDERS_SHOWN)
            ->get();
    }

    /**
     * Pick one of the orders already running here up, without leaving the counter.
     *
     * The orders page's own action, handed the order through the arguments it
     * was invoked with. Staff standing at the room can see that #13 is still
     * waiting and accept it there rather than going to find it in a list.
     */
    public function acceptOrderAction(): Action
    {
        return OrdersTable::acceptAction()
            ->iconButton()
            ->size(Size::Small)
            ->tooltip(__('panel.orders.accept'))
            ->record(fn (array $arguments): ?Order => $this->openOrderNamed($arguments))
            ->after(fn () => $this->openOrdersHere = null);
    }

    /**
     * Change one of them, for as long as it may be changed.
     */
    public function changeOrderAction(): Action
    {
        return OrdersTable::changeAction()
            ->iconButton()
            ->size(Size::Small)
            ->tooltip(__('panel.orders.change'))
            ->record(fn (array $arguments): ?Order => $this->openOrderNamed($arguments));
    }

    /**
     * Read one of the orders already running here, without leaving the counter.
     *
     * The orders list's own modal, handed the order through the arguments it
     * was invoked with — one definition of what reading an order shows, and
     * the reason there is no order page to navigate to any more.
     */
    public function viewOrderAction(): ViewAction
    {
        return OrdersTable::viewAction()
            // A link rather than the list's icon button: this sits inline in a
            // line of text naming the order, not in a row of controls.
            ->link()
            ->label(fn (array $arguments): string => '#'.(int) ($arguments['order'] ?? 0))
            ->record(fn (array $arguments): ?Order => $this->openOrderNamed($arguments));
    }

    /**
     * Add something with nothing to choose: a combo, or an item offering no groups.
     */
    public function addTile(string $type, int $id): void
    {
        $this->addLine($type, $id, [], 1);
    }

    /**
     * Pick an item's add-ons, then add it.
     *
     * The schema is built from the groups the item actually offers, with this
     * item's own cap on each: one pick is a plain select, several is a
     * multi-select capped at the picks allowed, and an option a guest may take
     * more than once gets a quantity beside it once it is chosen. How many of
     * an option one item may take is MenuAddOnGroup::quantityAllowedFor()'s
     * answer, never restated here.
     */
    public function customiseAction(): Action
    {
        return Action::make('customise')
            ->label(__('panel.take_order.customise'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->size(Size::ExtraSmall)
            ->modalHeading(fn (array $arguments): string => $this->itemNamed($arguments)->name ?? (string) __('panel.take_order.customise'))
            ->modalSubmitActionLabel(__('panel.take_order.add'))
            ->modalWidth(Width::Large)
            ->schema(fn (array $arguments): array => $this->customiseSchema($arguments))
            ->action(function (array $arguments, array $data): void {
                $itemId = (int) ($arguments['item'] ?? 0);
                $tile = $this->tileKeyed(ReadOrderableMenu::ITEM.':'.$itemId);

                if ($tile === null) {
                    return;
                }

                $this->addLine(
                    ReadOrderableMenu::ITEM,
                    $itemId,
                    $this->choicesFrom($tile['groupLinks'], $data),
                    max(1, (int) ($data['quantity'] ?? 1)),
                );
            });
    }

    public function increment(string $key): void
    {
        $this->changeQuantity($key, 1);
    }

    public function decrement(string $key): void
    {
        $this->changeQuantity($key, -1);
    }

    public function removeLine(string $key): void
    {
        $this->lines = array_values(array_filter(
            $this->lines,
            static fn (array $line): bool => $line['key'] !== $key,
        ));

        $this->priced = null;
    }

    public function emptyBasket(): null
    {
        $this->lines = [];
        $this->priced = null;

        return null;
    }

    /**
     * Place what is in the basket, and open the order it became.
     */
    public function placeOrder(): void
    {
        // mountCanAuthorizeAccess()/hydrateCanAuthorizeAccess() already refuse
        // someone who may not be here; this refuses the one thing this method
        // does, so the button cannot be reached by a stale page after a role
        // has been taken away mid-session.
        abort_unless(static::canAccess(), 403);

        if ($this->lines === []) {
            return;
        }

        $state = $this->form->getState();
        $menu = $this->menu();

        if (! $menu instanceof Menu) {
            return;
        }

        $location = $this->location();
        $changing = $this->orderBeingChanged();
        $settlement = OrderSettlement::from((string) $state['settlement']);
        $label = filled($state['location_label'] ?? null) ? (string) $state['location_label'] : null;
        $note = filled($state['note'] ?? null) ? (string) $state['note'] : null;

        try {
            $order = $changing instanceof Order
                ? app(ReviseOrder::class)(
                    tenant: $this->tenant(),
                    order: $changing,
                    menu: $menu,
                    lines: $this->basketLines(),
                    location: $location,
                    settlement: $settlement,
                    locationLabel: $label,
                    note: $note,
                )
                : app(PlaceOrder::class)(
                    tenant: $this->tenant(),
                    menu: $menu,
                    lines: $this->basketLines(),
                    location: $location,
                    settlement: $settlement,
                    locationLabel: $label,
                    note: $note,
                    // Staff standing in the kitchen are trusted with the clock.
                    allowOutsideHours: true,
                );
        } catch (LogicException $exception) {
            // ReviseOrder refusing: accepted since, or a payment now stands
            // against it. Both are a race this page cannot prevent, only report.
            Notification::make()
                ->title(__('panel.take_order.cannot_change'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } catch (OrderRefused $exception) {
            Notification::make()
                ->title(__('panel.take_order.refused'))
                ->body($exception->reason->message())
                ->danger()
                ->send();

            return;
        } catch (InsufficientStock $exception) {
            Notification::make()
                ->title(__('panel.take_order.short'))
                ->body($this->shortagesRead($exception))
                ->danger()
                ->send();

            return;
        }

        if ($changing instanceof Order) {
            Notification::make()
                ->title(__('panel.take_order.changed', ['number' => $order->getKey()]))
                ->success()
                ->send();

            // Back where the Change button was pressed, which is the orders page.
            $this->redirect(OrderResource::getUrl('index'), navigate: true);

            return;
        }

        Notification::make()
            ->title(__('panel.take_order.placed', ['number' => $order->getKey()]))
            ->success()
            ->send();

        // Staff stay at the counter. The order that was just placed appears in
        // "already running here" a line below, and the next one is taken
        // without navigating back — which is what a counter is for. This used
        // to redirect to the order's own page, and there is no such page now
        // that an order is read in a modal (OrdersTable::viewAction()).
        $this->emptyBasket();
        $this->data['note'] = null;
        $this->openOrdersHere = null;
        $this->floor = null;
    }

    /**
     * The sections this menu offers, narrowed to what has been typed into the search box.
     *
     * Filtered in PHP over rows already in memory rather than by a query per
     * keystroke: the whole card is loaded for the grid anyway.
     *
     * @return list<OrderableSection>
     */
    public function visibleSections(): array
    {
        $sections = $this->catalogue()['sections'];
        $search = trim($this->search);

        if ($search === '') {
            return $sections;
        }

        $matching = [];

        foreach ($sections as $section) {
            $tiles = array_values(array_filter(
                $section['tiles'],
                static fn (array $tile): bool => mb_stripos($tile['model']->name, $search) !== false,
            ));

            if ($tiles !== []) {
                $matching[] = [...$section, 'tiles' => $tiles];
            }
        }

        return $matching;
    }

    /**
     * What the basket comes to, as the guest app would have been told.
     *
     * @return array<string, mixed>
     */
    public function priced(): array
    {
        if ($this->priced !== null) {
            return $this->priced;
        }

        $menu = $this->menu();

        if ($this->lines === [] || ! $menu instanceof Menu) {
            return $this->priced = [
                'lines' => [],
                'subtotal' => 0,
                'tax' => 0,
                'taxParts' => null,
                'pricesIncludeTax' => false,
                'isUnionTerritory' => false,
                'charges' => [],
                'total' => 0,
                'shortages' => [],
            ];
        }

        return $this->priced = app(PriceBasket::class)($this->tenant(), $menu, $this->basketLines());
    }

    /**
     * One priced line, keyed by the basket key it belongs to.
     *
     * @return array<string, array<string, mixed>>
     */
    public function pricedLines(): array
    {
        $byKey = [];

        /** @var array<string, mixed> $line */
        foreach ($this->priced()['lines'] as $line) {
            $byKey[(string) $line['key']] = $line;
        }

        return $byKey;
    }

    /**
     * How many of an item or combo the basket already holds, across every line it is on.
     */
    public function heldCount(string $type, int $id): int
    {
        $held = 0;

        foreach ($this->lines as $line) {
            if ($line['type'] === $type && $line['id'] === $id) {
                $held += $line['quantity'];
            }
        }

        return $held;
    }

    /**
     * Whether one more of this tile would break the tenant's own cap on it.
     */
    public function isAtLimit(MenuItem|MenuCombo $tile, string $type): bool
    {
        return $tile->max_per_order !== null
            && $this->heldCount($type, $tile->getKey()) >= $tile->max_per_order;
    }

    public function basketCount(): int
    {
        return array_sum(array_column($this->lines, 'quantity'));
    }

    /**
     * Whether the doors are shut or this menu is outside its window — said on
     * the page rather than refused, because staff may order past it.
     *
     * @return list<string>
     */
    public function outsideHours(): array
    {
        $reasons = [];
        $menu = $this->menu();

        if (! $this->tenant()->isOpenAt()) {
            $reasons[] = (string) __('panel.take_order.tenant_closed');
        }

        if ($menu instanceof Menu && ! $menu->isBeingServedAt()) {
            $reasons[] = (string) __('panel.take_order.menu_not_served', ['name' => $menu->name]);
        }

        return $reasons;
    }

    public function currency(): Currency
    {
        return PricingFields::currency();
    }

    /**
     * A stored rate as it is read: 500 becomes "5%".
     */
    public function rate(int $basisPoints): string
    {
        return PricingFields::formatRate($basisPoints);
    }

    /**
     * Why a priced line no longer stands, or null while it does.
     *
     * The server is what refuses a line; the basket only reports what
     * PriceBasket answered, in the same two words the guest app uses.
     *
     * @param  array<string, mixed>|null  $pricedLine
     */
    public function lineProblem(?array $pricedLine): ?string
    {
        return match ($pricedLine['status'] ?? PriceBasket::OK) {
            PriceBasket::UNAVAILABLE => (string) __('panel.take_order.line_unavailable'),
            PriceBasket::INVALID => (string) __('panel.take_order.line_invalid'),
            default => null,
        };
    }

    /**
     * Whether a tile is an item rather than a combo — only an item carries a diet mark or add-ons.
     *
     * @param  array<string, mixed>  $tile
     */
    public function isItem(array $tile): bool
    {
        return $tile['type'] === ReadOrderableMenu::ITEM;
    }

    /**
     * The menu being ordered from.
     */
    public function menu(): ?Menu
    {
        $menuId = (int) ($this->data['menu_id'] ?? 0);

        foreach ($this->menus() as $menu) {
            if ($menu->getKey() === $menuId) {
                return $menu;
            }
        }

        return null;
    }

    /**
     * Where it goes, when it goes somewhere that is a row.
     */
    public function location(): ?Location
    {
        return $this->locationId === null ? null : $this->locationNamed($this->locationId);
    }

    /**
     * Every location with what is open at it, in the order staff want them,
     * read once per request however many times the page asks.
     *
     * Two queries for the whole picker — the same ReadFloor the orders page's
     * floor layout draws, so a room reads the same on both screens and is
     * sorted the same way.
     *
     * @return list<array{location: Location, activity: array<string, mixed>}>
     */
    private function floor(): array
    {
        return $this->floor ??= app(ReadFloor::class)($this->tenant());
    }

    /**
     * One of this tenant's own locations, by id.
     */
    private function locationNamed(int $locationId): ?Location
    {
        foreach ($this->floor() as $card) {
            if ($card['location']->getKey() === $locationId) {
                return $card['location'];
            }
        }

        return null;
    }

    /**
     * One of the orders running here, by the id an action was invoked with.
     *
     * Off the collection already loaded for the side column, so a row of
     * icon buttons costs no query of its own.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function openOrderNamed(array $arguments): ?Order
    {
        return $this->openOrdersHere()->firstWhere('id', (int) ($arguments['order'] ?? 0));
    }

    /**
     * The order this page was opened to change, if it may still be changed.
     *
     * Read once per request. Null for a new order, and null too for one that
     * has been accepted or paid since the link was followed — the page then
     * behaves as a new order rather than silently editing something fixed.
     */
    private function orderBeingChanged(): ?Order
    {
        if ($this->orderId === null) {
            return null;
        }

        if ($this->changing instanceof Order) {
            return $this->changing;
        }

        $order = Order::query()
            ->where('tenant_id', $this->tenant()->getKey())
            ->whereKey($this->orderId)
            ->withSum(['paymentAllocations as amount_paid' => fn ($allocations) => $allocations
                ->whereHas('payment', fn ($payment) => $payment->live())], 'amount')
            ->with(['lines' => fn ($lines) => $lines->orderBy('position')->orderBy('id'), 'lines.choices'])
            ->first();

        return $this->changing = ($order instanceof Order && $order->canBeChanged()) ? $order : null;
    }

    /**
     * Open the counter on an order that already exists, as its basket.
     *
     * The lines come back from the order's own copies — its names, not the
     * menu's — because that is what it was taken under and what staff are
     * looking at. A line whose item or combo has been deleted since cannot be
     * re-priced and is dropped, with a count of how many, rather than quietly
     * changing what the guest asked for.
     */
    private function fillFromOrder(Order $order): void
    {
        $this->locationId = $order->location_id;
        $this->isElsewhere = $order->location_id === null && filled($order->location_name);

        $dropped = 0;
        $lines = [];

        foreach ($order->lines as $line) {
            $type = $line->menu_combo_id !== null ? PriceBasket::COMBO : PriceBasket::ITEM;
            $id = $line->menu_combo_id ?? $line->menu_item_id;

            if ($id === null) {
                $dropped++;

                continue;
            }

            $choices = [];
            $choiceNames = [];

            foreach ($line->choices as $choice) {
                if ($choice->menu_add_on_option_id === null) {
                    continue;
                }

                $choices[] = ['optionId' => $choice->menu_add_on_option_id, 'quantity' => $choice->quantity];
                $choiceNames[] = $choice->quantity > 1 ? $choice->quantity.' × '.$choice->name : $choice->name;
            }

            $lines[] = [
                'key' => $this->keyFor($type, $id, $choices),
                'type' => $type,
                'id' => $id,
                'quantity' => $line->quantity,
                'choices' => $choices,
                'name' => $line->name,
                'choiceNames' => $choiceNames,
            ];
        }

        $this->lines = $lines;

        $this->form->fill([
            'menu_id' => $order->menu_id ?? ($this->menus()[0] ?? null)?->getKey(),
            'settlement' => $order->settlement->value,
            'note' => $order->note,
            'location_label' => $order->location_id === null ? $order->location_name : null,
        ]);

        if ($dropped > 0) {
            Notification::make()
                ->title(trans_choice('panel.take_order.lines_dropped', $dropped, ['count' => $dropped]))
                ->warning()
                ->send();
        }
    }

    /**
     * The basket in PriceBasket's own shape: the display names this page keeps
     * beside each line are no business of the pricing.
     *
     * @return list<BasketLine>
     */
    private function basketLines(): array
    {
        return array_map(static fn (array $line): array => [
            'key' => $line['key'],
            'type' => $line['type'],
            'id' => $line['id'],
            'quantity' => $line['quantity'],
            'choices' => $line['choices'],
        ], $this->lines);
    }

    /**
     * Add one thing to the basket, merging it into an identical line.
     *
     * @param  list<array{optionId: int, quantity: int}>  $choices
     */
    private function addLine(string $type, int $id, array $choices, int $quantity): void
    {
        $tile = $this->tileKeyed($type.':'.$id);

        if ($tile === null) {
            return;
        }

        $key = $this->keyFor($type, $id, $choices);

        foreach ($this->lines as $index => $line) {
            if ($line['key'] === $key) {
                $this->lines[$index]['quantity'] += $quantity;
                $this->priced = null;

                return;
            }
        }

        $this->lines[] = [
            'key' => $key,
            'type' => $type,
            'id' => $id,
            'quantity' => $quantity,
            'choices' => $choices,
            'name' => $tile['model']->name,
            'choiceNames' => $this->choiceNames($choices),
        ];

        $this->priced = null;
    }

    private function changeQuantity(string $key, int $by): void
    {
        foreach ($this->lines as $index => $line) {
            if ($line['key'] !== $key) {
                continue;
            }

            $quantity = $line['quantity'] + $by;

            if ($quantity < 1) {
                $this->removeLine($key);

                return;
            }

            $this->lines[$index]['quantity'] = $quantity;
            $this->priced = null;

            return;
        }
    }

    /**
     * What tells two lines of the same item apart: the choices made on it.
     *
     * @param  list<array{optionId: int, quantity: int}>  $choices
     */
    private function keyFor(string $type, int $id, array $choices): string
    {
        $parts = array_map(
            static fn (array $choice): string => $choice['optionId'].'x'.$choice['quantity'],
            $choices,
        );

        sort($parts);

        return $type.':'.$id.($parts === [] ? '' : ':'.implode(',', $parts));
    }

    /**
     * The modal's fields for one item: how many, then a field per group it offers.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<Component|Field>
     */
    private function customiseSchema(array $arguments): array
    {
        $tile = $this->tileKeyed(ReadOrderableMenu::ITEM.':'.(int) ($arguments['item'] ?? 0));

        if ($tile === null) {
            return [];
        }

        $item = $tile['model'];
        $groups = $this->catalogue()['groups'];
        $currency = $this->currency();

        $fields = [
            TextInput::make('quantity')
                ->label(__('panel.orders.quantity'))
                ->numeric()
                ->minValue(1)
                ->maxValue($item->max_per_order === null
                    ? 99
                    : max(1, $item->max_per_order - $this->heldCount(ReadOrderableMenu::ITEM, $item->getKey())))
                ->default(1)
                ->required(),
        ];

        foreach ($tile['groupLinks'] as $link) {
            $group = $groups->get($link['id']);

            if (! $group instanceof MenuAddOnGroup) {
                continue;
            }

            $maxPicks = $link['maxPicks'] ?? $group->max_picks;

            /** @var array<int, string> $options */
            $options = $group->options
                ->mapWithKeys(fn (MenuAddOnOption $option): array => [
                    $option->getKey() => $option->price === 0
                        ? $option->name
                        : $option->name.'  +'.$currency->format($option->price),
                ])
                ->all();

            /** @var list<int> $defaults */
            $defaults = $group->options
                ->filter(fn (MenuAddOnOption $option): bool => $option->is_default)
                ->map(fn (MenuAddOnOption $option): int => $option->getKey())
                ->values()
                ->all();

            // One pick is one value; anything else is the multi-select this
            // project uses wherever several answers are taken (.ai/rules/filament.md).
            $fields[] = $maxPicks === 1
                ? Select::make('group_'.$group->getKey())
                    ->label($group->name)
                    ->options($options)
                    ->default($defaults[0] ?? null)
                    ->required($group->is_required)
                    ->native(false)
                    ->live()
                : Select::make('group_'.$group->getKey())
                    ->label($group->name)
                    ->options($options)
                    ->multiple()
                    ->default($defaults)
                    ->maxItems($maxPicks)
                    ->required($group->is_required)
                    ->native(false)
                    ->live();

            foreach ($group->options as $option) {
                $allowed = $group->quantityAllowedFor($option, $maxPicks);

                if ($allowed < 2) {
                    continue;
                }

                $fields[] = TextInput::make('quantity_'.$option->getKey())
                    ->label(__('panel.take_order.how_many', ['name' => $option->name]))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue($allowed)
                    ->default(1)
                    ->visible(fn (Get $get): bool => $this->isPicked($get('group_'.$group->getKey()), $option->getKey()));
            }
        }

        return $fields;
    }

    /**
     * What ran out, named, so staff can tell the guest rather than read "insufficient stock".
     */
    private function shortagesRead(InsufficientStock $exception): string
    {
        $groups = $this->catalogue()['groups'];

        $named = array_map(function (array $shortage) use ($groups): string {
            $tile = $shortage['type'] === 'item'
                ? $this->tileKeyed(ReadOrderableMenu::ITEM.':'.$shortage['id'])
                : null;

            $name = $tile !== null
                ? $tile['model']->name
                : $groups
                    ->flatMap(fn (MenuAddOnGroup $group): array => $group->options->all())
                    ->firstWhere('id', $shortage['id'])?->name;

            return (string) __('panel.take_order.shortage', [
                'name' => $name ?? '#'.$shortage['id'],
                'requested' => $shortage['requested'],
                'available' => $shortage['available'],
            ]);
        }, $exception->shortages);

        return implode(' ', $named);
    }

    /**
     * Whether a group's answer — one value or several — holds this option.
     */
    private function isPicked(mixed $state, int $optionId): bool
    {
        return is_array($state)
            ? in_array($optionId, array_map(intval(...), $state), strict: true)
            : (int) $state === $optionId;
    }

    /**
     * The modal's answers as the choices a basket line carries.
     *
     * @param  list<array{id: int, maxPicks: int|null}>  $groupLinks
     * @param  array<string, mixed>  $data
     * @return list<array{optionId: int, quantity: int}>
     */
    private function choicesFrom(array $groupLinks, array $data): array
    {
        $groups = $this->catalogue()['groups'];
        $choices = [];

        foreach ($groupLinks as $link) {
            $group = $groups->get($link['id']);

            if (! $group instanceof MenuAddOnGroup) {
                continue;
            }

            $picked = $data['group_'.$group->getKey()] ?? null;

            foreach (is_array($picked) ? $picked : array_filter([$picked], fn (mixed $value): bool => filled($value)) as $optionId) {
                $optionId = (int) $optionId;
                $option = $group->options->firstWhere('id', $optionId);

                if (! $option instanceof MenuAddOnOption) {
                    continue;
                }

                $choices[] = [
                    'optionId' => $optionId,
                    'quantity' => min(
                        $group->quantityAllowedFor($option, $link['maxPicks'] ?? $group->max_picks),
                        max(1, (int) ($data['quantity_'.$optionId] ?? 1)),
                    ),
                ];
            }
        }

        return $choices;
    }

    /**
     * What a line's choices are called, kept on the line so drawing the basket asks no questions.
     *
     * @param  list<array{optionId: int, quantity: int}>  $choices
     * @return list<string>
     */
    private function choiceNames(array $choices): array
    {
        $names = [];

        foreach ($this->catalogue()['groups'] as $group) {
            foreach ($group->options as $option) {
                foreach ($choices as $choice) {
                    if ($choice['optionId'] !== $option->getKey()) {
                        continue;
                    }

                    $names[] = $choice['quantity'] > 1
                        ? $choice['quantity'].' × '.$option->name
                        : $option->name;
                }
            }
        }

        return $names;
    }

    /**
     * One tile of the current menu, by the key it was drawn under.
     *
     * @return array{type: string, key: string, model: MenuItem|MenuCombo, groupLinks: list<array{id: int, maxPicks: int|null}>}|null
     */
    private function tileKeyed(string $key): ?array
    {
        foreach ($this->catalogue()['sections'] as $section) {
            foreach ($section['tiles'] as $tile) {
                if ($tile['key'] === $key) {
                    return $tile;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function itemNamed(array $arguments): ?MenuItem
    {
        $tile = $this->tileKeyed(ReadOrderableMenu::ITEM.':'.(int) ($arguments['item'] ?? 0));

        return $tile !== null && $tile['model'] instanceof MenuItem ? $tile['model'] : null;
    }

    /**
     * The menu as it can be ordered right now, read once for the request
     * however many times the page and its modals ask for it.
     *
     * @return array{sections: list<OrderableSection>, groups: EloquentCollection<int, MenuAddOnGroup>}
     */
    private function catalogue(): array
    {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }

        $menu = $this->menu();

        return $this->catalogue = $menu instanceof Menu
            ? app(ReadOrderableMenu::class)($menu)
            : ['sections' => [], 'groups' => new EloquentCollection];
    }

    /**
     * This tenant's menus a guest could be reading, read once for the request.
     *
     * The service window is carried so the page can say a menu is outside it,
     * which it says rather than enforces.
     *
     * @return list<Menu>
     */
    private function menus(): array
    {
        return $this->menus ??= array_values(Menu::query()
            ->select(['id', 'name', 'available_from', 'available_until'])
            ->where('tenant_id', $this->tenant()->getKey())
            ->active()
            ->byName()
            ->get()
            ->all());
    }

    /**
     * @return array<int, string>
     */
    private function menuOptions(): array
    {
        $options = [];

        foreach ($this->menus() as $menu) {
            $options[$menu->getKey()] = $menu->name;
        }

        return $options;
    }

    private function tenant(): Tenant
    {
        $tenant = Filament::getTenant();

        throw_unless($tenant instanceof Tenant, LogicException::class, 'Taking an order requires a tenant.');

        return $tenant;
    }
}
