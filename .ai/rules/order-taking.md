---
paths:
  - app/Filament/Tenant/Resources/Orders/Pages/ListOrders.php
  - app/Filament/Tenant/Pages/TakeOrder.php
  - app/Actions/Orders/AdvanceOrder.php
  - app/Actions/Orders/ReviseOrder.php
  - app/Actions/Orders/NextOrderNumber.php
  - app/Actions/Menus/ReadOrderableMenu.php
  - 'resources/views/filament/tenant/resources/orders/pages/**'
  - resources/views/filament/tenant/pages/take-order.blade.php
---

# Order taking

## Two screens: the orders list, and one page that takes an order
Staff take orders at the desk and over the phone, and the only way an order existed was a guest's own phone. What answers that is a **list** of orders and a **counter** (`TakeOrder`) reached from its New order button — and nothing else.

**There was a third thing, "Places", and the project owner had it removed outright.** It was a second layout on the orders page, switched by a tabs strip: a card per room, table and delivery point, coloured by what was open at each, polling every ten seconds. Deleted with it: `ReadFloor`, `ReadLocationActivity`, `App\Enums\LocationActivity`, `places.blade.php`, `layout-switcher.blade.php`, the two `location-card` partials, `ListOrders::$layoutMode` and everything hanging off it. It had been renamed from "Floor" one revision earlier, and the cards had been through four passes of the project owner trimming them — a sign, in hindsight, that the screen was never earning its keep.

What went with it, deliberately: **a live view of the floor**. Nothing polls any more. "What is happening at Room 204" is the list's place filter, and what is running where an order is being taken is listed beside the card on the counter. Do not rebuild the board without a fresh reason; if one comes, it is derived from `orders` as that one was, never from a column on `locations` (`.ai/rules/locations.md`).

## The list: status tabs over a table that opens on today
One way to read the page. `ListOrders` is a plain `ListRecords` again — no `content()` override, no second layout.

- **Tabs are over status**, one per `OrderStatus` case plus All, each with a count badge. The project owner asked for the cuts staff make all shift to be one press rather than a filter to open and set.
- **The badges count what the tab will show.** They come from one grouped query through `filterTableQuery()` on the resource's own query — the **filters** applied, the **active tab** not. `getFilteredTableQuery()` would carry the tab (ListRecords applies it as the table's `modifyQueryUsing`), so every badge would show the tab already being read. A badge saying 40 over a list of 3 is worse than no badge.
- **Grouped by status rather than a conditional sum per case**: one query either way, and a status added later is counted without a line of SQL written for it.
- **Three filters, above the rows**: a date range defaulted to **today**, status and place, the last two multi-select. See "The orders list opens filtered to today" below.

## The counter: one page that takes an order and changes one
`TakeOrder` is reached from the list's New order button and from Change on an order. It is **not registered in the navigation** — an order is taken *about* something, not started cold from a sidebar link.

- **It is one page, not two.** It used to ask "where is this order going?" as a full-screen grid of place cards before showing the menu at all. That grid was Places' machinery, and its whole argument — "a dropdown cannot show what is already open at a room" — died with Places. So **where it goes is a `Select` again**, grouped by kind and searchable, beside the menu and the settlement. The earlier rule here said "never a select"; this reverses it, on the project owner's instruction.
- **Changing an order opens the same page**, with the place, the menu, the settlement and the basket already filled from the order. Nothing is re-picked.
- **Beside the card, what this place has already taken today** — `ordersHere()`, capped at `ORDERS_SHOWN`, every status including served, each opening the order modal, each with Advance and Change where they apply. It follows the select: changing the place changes the list (`forgetOrdersHere()`).
- **Still-being-worked first, then newest**, so the cap can only ever cut orders that are already finished; newest-first alone pushed the one order somebody was standing there asking about off the end of a busy day. The sort and the cut are PHP over one place's day, which is a bounded handful, rather than SQL — an `orderByRaw` built from `OrderStatus::underwayValues()` is not the literal string PHPStan requires. When the cap bites, a link to the full list says so.
- **Today's only**, which is the day the whole page works in.
- **`location_label` is the other path**: a tenant with no places at all, or an order going somewhere that is not a row. It shows when the select is empty, and a picked place wins over it in `PlaceOrder`.
- **The page draws no heading and no header.** It read "Take order · Room 101" and the project owner had it off. `getHeading()` returns `''` and there are no header actions, so Filament renders no header — which is why **Back is the page's own**, an icon button at the top left of `.to-top`. It must stay outside any branch: it is the only way out, and it once sat inside one.
- **Outside opening hours is a badge, not a bar.** It was a full-width section and the project owner had it off. Still said, because `allowOutsideHours` is passed from this page on purpose. Do not remove it entirely; the banner is the reason the button does not refuse.
- Every figure is `PriceBasket`'s, re-read on each change.

## Orders stays lit in the sidebar while staff are at the counter
`TakeOrder` registers no navigation item, so nothing at all was highlighted for the whole time an order was being taken or changed: the panel read as though you had left the orders module while you were in the middle of it.

`OrderResource::getNavigationItemActiveRoutePattern()` names the counter's route beside its own. Filament hands that list straight to `routeIs()`, and the route name is **asked for** (`TakeOrder::getRouteName()`) rather than written out, because a panel's id belongs to `App\Enums\FilamentPanel` alone (`.ai/rules/filament.md`). A page reached from a resource but registering no navigation of its own needs the same treatment. `TakeOrderTest` pins it against the rendered sidebar.

## An order is called by the tenant's own number, not by its id
`orders.number` plus `orders.numbered_on`: each tenant counts its own orders from 1 again **each day**. `orders.id` is global to the platform, so one tenant's first ever order is #1 and the next tenant's is #4,062 — not a number anybody reads back over a phone. `Order::reference()` is the one way it is written ("#012", padded to three), and **everything that prints an order says it that way**: the list column, every modal heading, the counter's headings and notifications, the settle picker, the stock history.

- **The pair identifies it, and neither half alone does.** Tomorrow has its own #012. Nothing looks an order up by its number; the id is still the key and still what the list sorts by. The number is what the list *searches*, because that is what staff are told.
- **`App\Actions\Orders\NextOrderNumber` is the only thing that assigns one**, from inside PlaceOrder's transaction. It takes `lockForUpdate()` on the **tenant row** before counting: no table here carries a unique index (`.ai/rules/migrations.md`), so nothing in the database would refuse the same number twice, and two members of staff pressing Place at once is the ordinary case at a counter. The lock makes the read-then-write a queue.
- **ReviseOrder keeps it.** The guest was told a number and it is the same order.

The `lang/en/panel.php` strings say `:number` and no longer carry a `#` of their own — the `#` comes from `reference()`. A string that hardcodes one will read "##012".

**One button moves an order along.** `OrdersTable::advanceAction()` reads its label, its icon and whether it exists at all from `OrderStatus`, so Accept → Mark ready → Hand over is one control rather than three, on a row and in the counter's side column alike. Only the first step confirms, because only the first decides something that cannot be undone. `AdvanceOrder` is its single action.

**An order is changed, not retyped, until it is accepted.** The project owner's rule: a guest rings back to add a coffee, and until somebody picks the order up staff should be able to change it. The **Change** row action links to the counter with `?order=`, which opens with that order already in its basket and saves through `ReviseOrder`; **Accept** is what closes the window. Both disappear the moment they no longer apply — `Order::canBeChanged()` is status *and* no live payment, and it is what the button's `visible()` reads. See `.ai/rules/inventory.md` for what revising does to stock.

**Every figure is `PriceBasket`'s**, re-read on each change, so a total read out over the phone is the total the guest's own phone would have shown: the same per-line GST, the same charges, the same rounding. Nothing in the page or its Blade adds up money. `TakeOrderTest` pins the page's total against a direct `PriceBasket` call.
- **It orders past closing.** `PlaceOrder` takes `allowOutsideHours`, and this page is its only caller. The banner says so rather than the button refusing. It waives the tenant's hours and the menu's service window and **nothing else**: a sold-out line, a broken group and a stock shortage still refuse.
- **It names a location by hand** (`location_label`) for a tenant with no locations, or an order going somewhere that is not a row — the same free-text path a guest who typed rather than picked uses. A picked location still wins over it in `PlaceOrder`.
- A basket is priced against one menu, so switching menus empties it.
- `ReadOrderableMenu` is the catalogue: flat sections (a sub-category is its own section, named under its parent), no rails, models rather than a payload, and add-on groups returned once for the whole menu with each item naming its own cap. What can be ordered is not decided again there — it is `MenuItem::orderable()`, `MenuAddOnOption::available()` and `MenuAddOnGroup::canBeMetBy()`, the same answers `Guest\MenuController` composes.
- The customise modal builds Filament fields from those groups: one pick is a `Select`, several is `Select->multiple()` capped at the picks allowed, and an option a guest may take more than once gets a numeric quantity beside it once chosen. How many of an option one item may take is `MenuAddOnGroup::quantityAllowedFor()`'s answer, never restated.

**Phone first, then tablet, then desk.** Staff take orders standing up, so the counter is one narrow column and gains a second from `lg` — `grid-template-areas: 'work here'`, taking the order on the left and the place on the right. On a phone the place comes **first** in the DOM, because that is the question staff arrived with, and the basket is a **sticky bar at the foot** holding the count, the total and the one button that matters. That bar is drawn at **every** width now: with the basket under a long card, Place is a scroll away on a desk too. Tap targets are thumb-sized and the repeated controls (add, choose, step, change location, orders here, back) are icon buttons with tooltips, never worded buttons competing with the primary one. **Customise is among them**: it was a worded "Choose" beside a round Add, so two tiles side by side ended in controls of different widths and the eye went to whichever item happened to offer add-ons.

**Back is the page's only way out, so it is drawn outside every branch.** There is no page header to hang it on, and it once sat inside an `@else` — pressing Back from a place landed on a screen with nothing to press. `TakeOrder::backUrl()` is now one answer, the orders list, because taking an order is one page rather than two.

**Styling.** Everything else is a `<style>` block plus Filament's own Blade components (`x-filament::section`, `badge`, `button`, `icon-button`, `link`, `input.wrapper`, `tabs`, `empty-state`) — a panel is served Filament's compiled CSS and no general Tailwind utilities (`.ai/rules/filament.md`), so layout is written by hand and anything with a theme is borrowed. Colours are Filament's palette variables through `color-mix`, the way the menu arrangement page does it, so they read correctly in both themes; `:is(.dark)` is the dark selector, which is what Filament puts on `<html>`.

**The list is six columns, and the money reads down the right of the modal.** Both on the project owner's instruction that the module was confusing.

- **The list** carried nine columns and scrolled sideways on anything but a desk. It is now the number, when it was placed, where it went (with its menu underneath), what it came to, its status and whether it has been paid. The menu, the line count and the settlement are still there, off by default, behind the table's own column toggle. `created_at` is deliberately **not** toggled off: it is what a day's orders are reconciled by, and `PanelDateTimeFormatTest` pins the house clock through it — hiding it by default broke that test, which is how the rule was found.
- **The modal** is a `Grid` of three: the lines take two columns and the totals the third, so subtotal, charges, GST, total and what is still outstanding read down the right the way they do on every bill a guest has been handed. They were spread across four columns underneath. The totals section is `inlineLabel()`, which is what makes it read as a receipt foot rather than as four more fields, and it stacks under the lines below `@lg`. Outstanding lives there now and was taken off the payments section, which was saying it twice.

**An action borrowed onto a page must be renamed to match the method holding it.** A page resolves a mounted action by its **name plus `Action`** — `mountAction('advance')` looks for `advanceAction()`. The counter defines `advanceOrderAction()`, `viewOrderAction()` and `changeOrderAction()`, so borrowing `OrdersTable::advanceAction()` unrenamed rendered `mountAction('advance')`, which resolved to nothing: **pressing Accept did nothing at all**, silently. Each now carries `->name('advanceOrder')` and so on.

Two things hid it, and both are worth knowing before writing the test:
- **Asking by the method name resolves down a different branch.** `TestAction::make('advanceOrderAction')` matches `method_exists($this, $name)` and passes whatever the action is called, so the tests were green while the button was dead. A test has to use the name the **button renders with** (`$action->getName()`).
- **Rendering caches the action under its own name**, so `getAction('advance')` succeeds in a test that has already rendered the page. In the browser the mount arrives on a fresh request, before anything renders, with an empty cache. `OrderChangeTest` therefore checks Filament's resolution rule against a fresh `app(TakeOrder::class)` rather than calling `getAction()`.

**An action echoed into Blade renders disabled, not hidden, when its `visible()` is false.** Filament leaves that filtering to whatever holds the action — a table's row, a page's header — and a `foreach` in a view is neither, so `{{ ($this->advanceOrderAction)([...]) }}` on an order with nowhere left to go drew a dead button (`isDisabled()` returns true for a hidden action). Ask first: `$action = ($this->advanceOrderAction)([...])`, then `@if ($action->isVisible())`. This showed up the moment the counter's list widened to served orders, and `TakeOrderTest` asserts no `fi-disabled` survives on one.

**The orders list opens filtered to today, and says so.** Three controls above the rows — a date range, status and place, the last two multi-select — with the range `default()`ed to today. Defaulted rather than written into the query, so clearing the dates really does show everything; a default in the query would be a floor nobody could get under. This is the one table that shows its filters and its indicators: `hiddenFilterIndicators()` is the house setting because elsewhere indicators repeat the controls beside them, but a list that **opens holding rows back** has to say so. A deep link into it sets `filters[location_id][values][]` — **plural**, because a multiple `SelectFilter` reads a different key from a single one — and clears the date range, since "everything this place has taken" is the question it asks.

**Pressing a row reads the order**, via `->recordAction('view')`, and the modal carries Change and Cancel in its footer so an order can be acted on without closing it and finding the row again. Both hide themselves the moment they no longer apply.

**An order is read in a modal, and there is no order page.** `OrdersTable::viewAction()` is the one definition of it — a wide, read-only `ViewAction` rendering `OrderInfolist` — used from a list row and from the counter's "already running here" list. `ViewOrder` was deleted with it, and so was the route-binding query that eager-loaded the record: `mountUsing()` calls `OrderResource::loadForView()` instead, which **`loadMissing`s** rather than `load`s, because the list already eager-loads each row's menu and loading it twice is the duplicate the query guard throws on. Anything that hands an order to that modal must have selected the whole row — `TakeOrder::openOrdersHere()` deliberately selects no column list for exactly that reason.

**Every place carries its kind's icon** — `LocationKind::icon()` on the counter's place panel and the Locations table's badge — and every order carries its status's (`OrderStatus::icon()`), on the list, in the modal and in the counter's side column. A room should read as a room wherever it is drawn.

## Not built yet
- **Parcel and takeaway orders.** Deferred by the project owner. An order goes to a `location` or to typed free text, and neither says "collected at the counter" or "sent out". When it arrives it is likely a kind of *order*, not a kind of location.
- **Who is in the room.** A guest checked into a room or seated at a table. Deferred too, and `.ai/rules/locations.md` still holds: `locations` carries nothing about occupancy, and when it comes it is a table of its own anchored on `locations.id` — never a `status` or an `occupied_by` column.
- **Steps past served**, and a kitchen screen of its own.

Tests: `tests/Feature/Tenant/TakeOrderTest.php`, `tests/Feature/Tenant/OrderChangeTest.php`, `tests/Feature/Tenant/OrderManagementTest.php`, `tests/Feature/Tenant/OrderNumberTest.php`.
