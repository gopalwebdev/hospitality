<?php

namespace App\Filament\Tenant\Pages;

use App\Actions\Baskets\PriceBasket;
use App\Actions\Menus\ReadOrderableMenu;
use App\Actions\Orders\PlaceOrder;
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
use Illuminate\Support\Facades\Date;
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

    /** The most of a place's orders the panel lists before it stops. */
    private const int ORDERS_SHOWN = 8;

    /**
     * The order being changed, when this is a change rather than a new order.
     *
     * Also in the query string, because the Change button on the orders page
     * is a link to it and going back from here has to land where it came from.
     */
    #[Url(as: 'order')]
    public ?int $orderId = null;

    /** @var list<Location>|null */
    private ?array $locations = null;

    /** @var list<Menu>|null */
    private ?array $menus = null;

    /** @var array{sections: list<OrderableSection>, groups: EloquentCollection<int, MenuAddOnGroup>}|null */
    private ?array $catalogue = null;

    /** @var array<string, mixed>|null */
    private ?array $priced = null;

    /** @var EloquentCollection<int, Order>|null */
    private ?EloquentCollection $ordersHere = null;

    private bool $ordersHereTruncated = false;

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

        // Not changing anything: a new order. `?location=` still opens the
        // page on a place — the Places cards that used to send it are gone,
        // but the orders list links this way and so does anything bookmarked.
        // An id belonging to another tenant is simply not filled.
        $this->orderId = null;

        $asked = (int) request()->integer('location');

        $this->form->fill([
            'menu_id' => ($this->menus()[0] ?? null)?->getKey(),
            'location_id' => $this->isOwnLocation($asked) ? $asked : null,
            'settlement' => OrderSettlement::AddToBill->value,
        ]);
    }

    public function getTitle(): string
    {
        return (string) ($this->isChangingAnOrder()
            ? __('panel.take_order.change_title', ['number' => $this->changingReference()])
            : __('panel.take_order.title'));
    }

    /**
     * No page heading at all.
     *
     * It read "Take order · Room 101" and the project owner had it off: the
     * place is named in the panel on the right, the browser tab still carries
     * getTitle(), and a heading repeating it cost a line of screen on a phone
     * for nothing. With no heading and no header actions Filament draws no
     * header, which is why Back is rendered by the page's own view instead
     * (top left, where it was asked for).
     */
    public function getHeading(): string
    {
        return '';
    }

    /**
     * What the order being changed is called — "#012", the tenant's own count
     * for the day rather than the row's id (`Order::reference()`).
     */
    public function changingReference(): ?string
    {
        return $this->orderBeingChanged()?->reference();
    }

    /**
     * Whether this is a change to an order that already exists.
     */
    public function isChangingAnOrder(): bool
    {
        return $this->orderId !== null;
    }

    /**
     * Where Back goes: the orders list, which is the only way in now.
     *
     * It had three cases while taking an order was two screens — the picker
     * and then the counter — and going back from the second meant the first.
     * Taking an order is one page, so there is one step up.
     */
    public function backUrl(): string
    {
        return OrderResource::getUrl('index');
    }

    /**
     * Whether this id names one of this tenant's own places.
     */
    private function isOwnLocation(int $locationId): bool
    {
        foreach ($this->locations() as $location) {
            if ($location->getKey() === $locationId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every order this place has ever taken, on the orders list.
     *
     * The floor's cards used to carry a button crossing to the list with this
     * filter already set, and the project owner had the cards stripped to one
     * tap target. The question it answered — "show me everything at 204, not
     * only what is still open" — is a real one, so it is asked from here
     * instead, beside the handful of open orders this page lists.
     *
     * The key is `filters`, not `tableFilters`: that is what ListRecords binds
     * the property to, and the wrong one is not an error but an unread query
     * parameter and a list showing every order (`.ai/rules/tables.md`).
     */
    public function ordersHereUrl(Location $location): string
    {
        return OrderResource::getUrl('index', [
            'filters' => [
                // `values`, plural: the location filter takes several, and a
                // multiple SelectFilter reads a different key from a single
                // one. Sending `value` is not an error — it is an unread
                // parameter and a list showing every place's orders.
                'location_id' => ['values' => [(string) $location->getKey()]],
                // Cleared, because the question this link asks is "everything
                // this place has taken", and the list opens on today alone.
                'placed_between' => ['from' => null, 'until' => null],
            ],
        ]);
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

                        // **A select again.** It was one, then a full-screen
                        // grid of cards for a while — a dropdown could not
                        // show what was already open at a room, which was the
                        // Places module's whole argument. The project owner
                        // has since had Places removed, so that argument went
                        // with it: taking an order is one page now, and the
                        // orders already at the chosen place are listed beside
                        // the card rather than on the way to it. Grouped by
                        // kind and searchable, so fifty rooms is a type-ahead
                        // rather than a scroll.
                        Select::make('location_id')
                            ->label(__('panel.take_order.location'))
                            ->options(fn (): array => $this->locationOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder(__('panel.take_order.elsewhere'))
                            ->prefixIcon(Heroicon::OutlinedMapPin)
                            ->visible(fn (): bool => $this->hasAnyLocation())
                            // The side column follows the answer, and so does
                            // what counts as "already here".
                            ->live()
                            ->afterStateUpdated(fn (): null => $this->forgetOrdersHere()),

                        // The other path, and the only one for a tenant that
                        // has set up no locations at all: name it by hand. A
                        // picked location wins over it, exactly as it does for
                        // a guest who typed rather than picked (PlaceOrder).
                        TextInput::make('location_label')
                            ->label(__('panel.take_order.location_label'))
                            ->maxLength(120)
                            ->visible(fn (Get $get): bool => ! $this->hasAnyLocation() || blank($get('location_id'))),

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
        return $this->locations() !== [];
    }

    /**
     * Where an order may go, grouped by what kind of place each is.
     *
     * Grouped rather than one flat list, because a hotel's fifty rooms and
     * its three delivery points read as two different questions, and the
     * select is searchable so the groups cost nothing to scroll past. The
     * code rides on the label — "204" is what staff type — where it is not
     * already part of the name.
     *
     * @return array<string, array<int, string>>
     */
    public function locationOptions(): array
    {
        $options = [];

        foreach ($this->locations() as $location) {
            $label = $location->name;

            if (filled($location->code) && mb_stripos($location->name, (string) $location->code) === false) {
                $label .= ' · '.$location->code;
            }

            $options[$location->kind->label()][$location->getKey()] = $label;
        }

        return $options;
    }

    /**
     * Forget what was read for the last place, so the side column follows the
     * select rather than the answer it was first given.
     */
    public function forgetOrdersHere(): null
    {
        $this->ordersHere = null;

        return null;
    }

    /**
     * Every order this place has taken today, newest first, whatever state
     * each is in.
     *
     * This is what a member of staff came for: they pressed the place on the
     * Places layout to find out what is going on at it. So a **served** order
     * is listed too, with its status beside it — leaving it out answered
     * "what still needs work" rather than "what is happening here", and staff
     * reading a bill back to a guest need the ones already handed over.
     *
     * **Today's only**, which is the day the whole page works in. Capped at
     * ORDERS_SHOWN because a busy room would otherwise grow a list nobody
     * scrolls — and ordered **still-being-worked first**, so the cap can only
     * ever cut off orders that are already finished. Everything a place has
     * ever taken is one press further on (`ordersHereUrl()`).
     *
     * @return EloquentCollection<int, Order>
     */
    public function ordersHere(): EloquentCollection
    {
        if ($this->ordersHere instanceof EloquentCollection) {
            return $this->ordersHere;
        }

        $location = $this->location();

        if (! $location instanceof Location) {
            return $this->ordersHere = new EloquentCollection;
        }

        // Every column, unusually: any of these rows can be opened in the
        // order modal, which reads the whole record — its totals, its GST
        // split, its note — and a row selected down to five columns throws on
        // the first one the infolist asks for (Model::shouldBeStrict()). The
        // list is capped, so this is a handful of rows.
        // Today's orders for this one place: a bounded handful, so they are
        // sorted and cut in PHP rather than in SQL. Every column, unusually:
        // any of these rows can be opened in the order modal, which reads the
        // whole record — its totals, its GST split, its note — and a row
        // selected down to five columns throws on the first one the infolist
        // asks for (Model::shouldBeStrict()).
        $found = Order::query()
            ->where('location_id', $location->getKey())
            ->whereDate('created_at', Date::now()->startOfDay())
            // Aliased exactly amount_paid and constrained to live payments,
            // the contract Order::amountPaid() reads it back under.
            ->withSum(['paymentAllocations as amount_paid' => fn ($allocations) => $allocations
                ->whereHas('payment', fn ($payment) => $payment->live())], 'amount')
            ->latest('id')
            ->get();

        $this->ordersHereTruncated = $found->count() > self::ORDERS_SHOWN;

        // Still being worked first, then newest. The cap is what makes this
        // matter: without it a busy room could push the one order somebody is
        // standing there asking about off the end of the list.
        return $this->ordersHere = $found
            ->sortBy(static fn (Order $order): int => $order->isUnderway() ? 0 : 1)
            ->take(self::ORDERS_SHOWN)
            ->values();
    }

    /**
     * Whether this place has taken more today than the panel is showing.
     */
    public function hasMoreOrdersHere(): bool
    {
        $this->ordersHere();

        return $this->ordersHereTruncated;
    }

    /**
     * How many orders are still being worked here, for the panel's heading.
     *
     * Read off the very figure the card on Places draws, rather than counted
     * again from the list — the two disagreeing is the bug this pair was
     * built to close, and one source cannot disagree with itself.
     */
    public function openOrdersHereCount(): int
    {
        return $this->ordersHere()->filter(static fn (Order $order): bool => $order->isUnderway())->count();
    }

    /**
     * Move one of the orders running here one step along, without leaving the counter.
     *
     * The orders page's own action, handed the order through the arguments it
     * was invoked with. Staff standing at the room can see that #13 is ready
     * and hand it over there rather than going to find it in a list.
     *
     * **Renamed to match the method.** The action borrowed from `OrdersTable`
     * is called `advance`, and a page resolves a mounted action by looking
     * for a method of that name plus `Action` — `advanceAction()`, which this
     * page does not have. So the button rendered `mountAction('advance')`,
     * Filament resolved nothing, and **pressing Accept did nothing at all**.
     * Tests did not catch it because `TestAction` is usually given the
     * *method* name, which resolves down a different branch; they now use the
     * action's own name, which is the one the browser sends.
     */
    public function advanceOrderAction(): Action
    {
        return OrdersTable::advanceAction()
            ->name('advanceOrder')
            ->iconButton()
            ->size(Size::Small)
            ->record(fn (array $arguments): ?Order => $this->openOrderNamed($arguments))
            ->after(fn () => $this->ordersHere = null);
    }

    /**
     * Change one of them, for as long as it may be changed.
     */
    public function changeOrderAction(): Action
    {
        return OrdersTable::changeAction()
            ->name('changeOrder')
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
            // Named for the method that defines it, or nothing resolves when
            // the rendered button mounts it — see advanceOrderAction().
            ->name('viewOrder')
            // A link rather than the list's icon button: this sits inline in a
            // line of text naming the order, not in a row of controls.
            ->link()
            ->label(fn (array $arguments): string => $this->openOrderNamed($arguments)?->reference() ?? '')
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
            // An icon button, the same size and shape as the plain Add beside
            // it on a tile with nothing to choose. It was a worded "Choose"
            // button, which made two tiles side by side end in controls of
            // different widths and drew the eye to whichever item happened to
            // offer add-ons. The tooltip is what names it.
            ->iconButton()
            ->tooltip(__('panel.take_order.customise'))
            ->size(Size::Medium)
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
                ->title(__('panel.take_order.changed', ['number' => $order->reference()]))
                ->success()
                ->send();

            // Back where the Change button was pressed, which is the orders page.
            $this->redirect(OrderResource::getUrl('index'), navigate: true);

            return;
        }

        Notification::make()
            ->title(__('panel.take_order.placed', ['number' => $order->reference()]))
            ->success()
            ->send();

        // Staff stay on the page. The order that was just placed appears in
        // the list beside the card, and the next one is taken without
        // navigating back — which is what a counter is for. This used to
        // redirect to the order's own page, and there is no such page now
        // that an order is read in a modal (OrdersTable::viewAction()).
        $this->emptyBasket();
        $this->data['note'] = null;
        $this->forgetOrdersHere();
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
        $locationId = (int) ($this->data['location_id'] ?? 0);

        foreach ($this->locations() as $location) {
            if ($location->getKey() === $locationId) {
                return $location;
            }
        }

        return null;
    }

    /**
     * This tenant's places an order may go to, read once per request.
     *
     * @return list<Location>
     */
    private function locations(): array
    {
        return $this->locations ??= array_values(Location::query()
            ->select(['id', 'name', 'kind', 'code'])
            ->where('tenant_id', $this->tenant()->getKey())
            ->active()
            ->inReadingOrder()
            ->get()
            ->all());
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
        return $this->ordersHere()->firstWhere('id', (int) ($arguments['order'] ?? 0));
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
            // The place it was taken for, so changing an order opens on the
            // same page as taking one and nothing has to be re-picked.
            'location_id' => $order->location_id,
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
