---
paths:
  - app/Filament/Tenant/Resources/Orders/Pages/ListOrders.php
  - app/Filament/Tenant/Pages/TakeOrder.php
  - app/Actions/Orders/ReadFloor.php
  - app/Actions/Orders/AdvanceOrder.php
  - app/Actions/Orders/ReviseOrder.php
  - app/Actions/Orders/ReadLocationActivity.php
  - app/Actions/Menus/ReadOrderableMenu.php
  - app/Enums/LocationActivity.php
  - 'resources/views/filament/tenant/resources/orders/pages/**'
  - resources/views/filament/tenant/pages/take-order.blade.php
  - resources/views/filament/tenant/partials/location-card.blade.php
  - resources/views/filament/tenant/partials/location-card-styles.blade.php
---

# Order taking

## Order taking in the panel: the board and the counter
Two pages in the tenant panel, added because staff take orders at the desk and over the phone and the only way an order existed was a guest's own phone.

**The floor** is one of the **two layouts the orders page is read in** — `ListOrders::$layoutMode` is `list` or `floor`, switched by a tabs strip above whichever is showing, and `content()` returns either `EmbeddedTable::make()` or the floor's view. It was its own `OrderBoard` page for one revision and the project owner had it folded in: two navigation entries for one subject is one too many, and "show me the floor" is a way of looking at orders rather than a different thing.

Every active location is a card: its kind, its code, whether anything is open there, what it owes and how long ago the newest order landed. Filters are a kind tab list, a search over name *and* code ("204" finds Room 204), and Only open. A card offers Take order, Settle, and a button that crosses to the **list** with that location's filter already set (`showOrdersAt()`) — a card is the question and the list is the answer, so the filter is set on the way across.

Two things the property must keep: it is `$layoutMode` and **not `$layout`**, because `Filament\Pages\Page` already declares a static `$layout` and a non-static property of that name is a fatal error; and the summary strip is drawn only while something is open, since three zeroes over a quiet floor is the same noise as the badge below.

- **Live by polling, never by push.** `wire:poll` on the grid, 10s, one grouped query per tick (`ReadLocationActivity`) however many cards are drawn. There is no Reverb or Echo in this project and none was added for it; do not reach for websockets here without a fresh reason.
- **Nothing is stored.** `App\Enums\LocationActivity` (`Ready`, `Pending`, `Preparing`, `Clear`) is derived on every read, and a card headlines with the loudest of whatever is there. "Open" here means an order still **underway** — pending, being made or ready — never one that owes money: a served order leaves the floor unpaid, and a cancelled one was never work at all. `.ai/rules/locations.md` forbids a `locations.status` column and this is what honours it.
- **`ReadFloor` is the one place the cards are built and ordered**, and both the floor layout and the counter's picker call it, so a room reads and sorts the same on both. The order is the project owner's: **anything owing first, newest order at the top** (which puts a just-landed order top without a second rule for it), then everywhere quiet, in the tenant's own reading order among themselves — PHP's sort is stable, so a quiet floor does not shuffle on every poll. Filtering is left to the page asking, over the list already in memory.
- **A quiet card says one thing.** It carried a "Clear" badge and a ₹0.00 and the project owner had both removed: a badge on every card says nothing, and a total of nothing is not a total. `location-card.blade.php` draws the badge and the amount only once something is open.
- **`Order::amountPaidExpression()`** is the one copy of the correlated "what has this order been paid" SQL. `scopeUnsettled()`, `scopeSettled()` and the board's sum all read it. Do not type it out a fourth time.
- **Settle is reused, not rewritten.** The board calls `LocationsTable::settleAction()` and hands it the card's location through `->record(fn (array $arguments) => ...)` off the already-loaded collection. Its `visible()` is overridden because the table's own runs a query per row, which on a board of fifty rooms would be fifty queries every poll.

**The floor is about work, not money.** The project owner's instruction: a card says how many orders are pending, being made and ready to carry over — and nothing about what the room owes. The ₹ figures, the Outstanding tile and the Settle button all came off it; a bill is the **list** layout's business and the Locations page's Settle (`.ai/rules/payments.md`). `ReadLocationActivity` sums no amount, and a **served** order leaves the floor even though it may still be unpaid. A quiet card says "Nothing open" and nothing else — no badge, no total of nothing.

**One button moves an order along.** `OrdersTable::advanceAction()` reads its label, its icon and whether it exists at all from `OrderStatus`, so Accept → Mark ready → Hand over is one control rather than three, on a row and in the counter's side column alike. Only the first step confirms, because only the first decides something that cannot be undone. `AdvanceOrder` is its single action.

**An order is changed, not retyped, until it is accepted.** The project owner's rule: a guest rings back to add a coffee, and until somebody picks the order up staff should be able to change it. The **Change** row action links to the counter with `?order=`, which opens with that order already in its basket and saves through `ReviseOrder`; **Accept** is what closes the window. Both disappear the moment they no longer apply — `Order::canBeChanged()` is status *and* no live payment, and it is what the button's `visible()` reads. See `.ai/rules/inventory.md` for what revising does to stock.

**The counter** (`TakeOrder`) asks **where first**, then shows the card on one side and the basket on the other. It is **not registered in the navigation** — an order is taken *about* somewhere, so it is reached from a board card (`?location=`, which skips the question) or from the Orders page's New order header action.

- **Where it goes is a grid, never a select.** There was a searchable `location_id` Select on the form and the project owner had it replaced: a tenant with fifty rooms is a fifty-row dropdown, and — the actual point — a dropdown cannot show what is already open at a room before staff add another order to it. The picker is the board's own cards, `chooseLocation()` on each, with a search over name and code and a dashed **Somewhere else** tile for the free-text path. Do not put a location select back on this form.
- **Once picked, the side column says what is already running there**: the location with its live state and outstanding, then its open orders (`openOrdersHere()`, capped at `OPEN_ORDERS_SHOWN`, each opening the order modal) and an icon button back to the grid. It was a full-width strip above the card for one revision and the project owner had it moved: context belongs beside the work, not on top of it, and a strip pushed the whole counter down a screen. The basket survives a change of mind about the table.
- Location lives on the component (`$locationId`, `$isElsewhere`), **not** in the form's `$data`. Only `location_label` stayed a field, visible when Somewhere else was chosen or the tenant has no locations at all — and a tenant with none never sees the question.
- The menu, the basket and their queries are all behind the question: `ReadOrderableMenu` and `PriceBasket` are not called while the picker is up.

- **Every figure is `PriceBasket`'s**, re-read on each change, so a total read out over the phone is the total the guest's own phone would have shown: the same per-line GST, the same charges, the same rounding. Nothing in the page or its Blade adds up money. `TakeOrderTest` pins the page's total against a direct `PriceBasket` call.
- **It orders past closing.** `PlaceOrder` takes `allowOutsideHours`, and this page is its only caller. The banner says so rather than the button refusing. It waives the tenant's hours and the menu's service window and **nothing else**: a sold-out line, a broken group and a stock shortage still refuse.
- **It names a location by hand** (`location_label`) for a tenant with no locations, or an order going somewhere that is not a row — the same free-text path a guest who typed rather than picked uses. A picked location still wins over it in `PlaceOrder`.
- A basket is priced against one menu, so switching menus empties it.
- `ReadOrderableMenu` is the catalogue: flat sections (a sub-category is its own section, named under its parent), no rails, models rather than a payload, and add-on groups returned once for the whole menu with each item naming its own cap. What can be ordered is not decided again there — it is `MenuItem::orderable()`, `MenuAddOnOption::available()` and `MenuAddOnGroup::canBeMetBy()`, the same answers `Guest\MenuController` composes.
- The customise modal builds Filament fields from those groups: one pick is a `Select`, several is `Select->multiple()` capped at the picks allowed, and an option a guest may take more than once gets a numeric quantity beside it once chosen. How many of an option one item may take is `MenuAddOnGroup::quantityAllowedFor()`'s answer, never restated.

**A location card is drawn in one place.** `resources/views/filament/tenant/partials/location-card.blade.php` is its markup — name, kind, code, capacity, state badge, outstanding and open-order count — and `location-card-styles.blade.php` is its look, the `lc-` classes. The board wraps it in a section with actions; the counter's picker wraps it in a `<button>`. Both draw the same card because it would otherwise drift, and the styles partial is included **once per page** while the markup partial is included once per card. Anything a card gains goes in the partial, never in one page's copy.

**Phone first, then tablet, then desk.** Staff take orders standing up, so the counter is laid out for one narrow column and gains a second from `lg`; the floor's cards are one per row below `30rem`. On a phone the basket is a **sticky bar at the foot** holding the count, the total and the one button that matters, so an order can be placed without scrolling to it — the guest app's answer, for the guest app's reason. It is hidden from `lg`, where the basket sits beside the card and the bar would say it twice. Tap targets are thumb-sized and the repeated controls (add, step, change location, back) are icon buttons with tooltips, never worded buttons competing with the primary one.

**Back goes up one step, and the URL says where you are.** `TakeOrder::backUrl()` is one rule — changing an order came from the orders page, a room came from the picker, the picker came from the orders page — and it is a header icon button. The state it reads lives in the query string (`#[Url]` on `$locationId`, `$orderId`, and on `ListOrders::$layoutMode` and `$kind`), so the browser's own back button agrees with it instead of dropping you on a page that has forgotten which layout you were reading.

**Styling.** Everything else is a `<style>` block plus Filament's own Blade components (`x-filament::section`, `badge`, `button`, `icon-button`, `link`, `input.wrapper`, `tabs`, `empty-state`) — a panel is served Filament's compiled CSS and no general Tailwind utilities (`.ai/rules/filament.md`), so layout is written by hand and anything with a theme is borrowed. Colours are Filament's palette variables through `color-mix`, the way the menu arrangement page does it, so they read correctly in both themes; `:is(.dark)` is the dark selector, which is what Filament puts on `<html>`.

**An order is read in a modal, and there is no order page.** `OrdersTable::viewAction()` is the one definition of it — a wide, read-only `ViewAction` rendering `OrderInfolist` — used from a list row and from the counter's "already running here" list. `ViewOrder` was deleted with it, and so was the route-binding query that eager-loaded the record: `mountUsing()` calls `OrderResource::loadForView()` instead, which **`loadMissing`s** rather than `load`s, because the list already eager-loads each row's menu and loading it twice is the duplicate the query guard throws on. Anything that hands an order to that modal must have selected the whole row — `TakeOrder::openOrdersHere()` deliberately selects no column list for exactly that reason.

**Every place carries its kind's icon** — `LocationKind::icon()` on the floor's cards, the counter's picker and location strip, and the Locations table's badge — and every order carries its status's (`OrderStatus::icon()`), on the list, in the modal and in the counter's side column. A room should read as a room wherever it is drawn.

## Not built yet
- **Parcel and takeaway orders.** Deferred by the project owner. An order goes to a `location` or to typed free text, and neither says "collected at the counter" or "sent out". When it arrives it is likely a kind of *order*, not a kind of location.
- **Who is in the room.** A guest checked into a room or seated at a table, so a card can say who is there. Deferred too, and `.ai/rules/locations.md` still holds: `locations` carries nothing about occupancy, and when it comes it is a table of its own anchored on `locations.id` — never a `status` or an `occupied_by` column.
- **Steps past served**, and a kitchen screen of its own.

Tests: `tests/Feature/Tenant/TakeOrderTest.php`, `tests/Feature/Tenant/OrderFloorTest.php`, `tests/Feature/Tenant/OrderChangeTest.php`, `tests/Feature/Tenant/OrderManagementTest.php`.
