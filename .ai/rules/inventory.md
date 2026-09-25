---
paths:
  - 'app/Actions/Inventory/**'
  - 'app/Actions/Orders/**'
  - 'app/Filament/Tenant/Resources/Orders/**'
  - 'app/Filament/Schemas/StockFields.php'
  - 'app/Filament/Tables/StockActions.php'
  - 'app/Models/Order.php'
  - 'app/Models/OrderLine.php'
  - 'app/Models/OrderLineChoice.php'
  - 'app/Models/OrderCharge.php'
  - 'app/Models/StockMovement.php'
---

# Inventory

## A count on the row, a history beside it, and blank is nobody counting
Stock is the pair Square, Shopify and Toast all keep:

- **The count:** `menu_items.stock_quantity` and `menu_add_on_options.stock_quantity`, how many are left. **Null is not counted** — tea, a spice level — and a row nobody counts never runs out. An option's count is shared by every item offering its group.
- **The history:** `stock_movements`, one row per change:
  - whose count: `menu_item_id` or `menu_add_on_option_id`, exactly one (`stock_movements_names_one_thing`) — two real keys rather than a polymorphic pair, because every relationship is a foreign key
  - why: `App\Enums\StockMovementReason` (restock, count, order-placed, order-cancelled)
  - the signed `quantity_change` and the `quantity_after`
  - the order or the panel user behind it, and a note

  A movement is never edited, so the table has no `updated_at`. `RecordStockMovement` is its only writer.

**Combos have no count.** A combo line takes each of its contents' counts, `menu_combo_items.quantity` times over (`StockDemand`), so a biryani and a biryani meal draw on one pot.

## Stock is written under a lock, and only through ApplyStockChanges
An existing row's count changes only through `App\Actions\Inventory\ApplyStockChanges`. Everything that changes stock goes through it: PlaceOrder, CancelOrder, the Adjust stock action, and the forms.

- **Lock:** every row named is read again `FOR UPDATE`, items before options, each in key order, so two orders placed at once queue on the same rows in the same order instead of deadlocking.
- **All or none:** a take asking a counted row for more than it has left throws `InsufficientStock`, naming every short row, and writes nothing.
- **Three kinds of change** (`StockChange`): `take` for an order, `add` for a restock or a cancelled order, `setTo` for a count (null stops counting). A take or an add does nothing to a row nobody counts.
- **Every change writes a movement**, except stopping counting, which leaves nothing to say how many are left.

A new row's count is stored with the row — `stock_quantity` is fillable for exactly that, because nothing can have taken from a row that did not exist — and `MenuItemObserver::created()` / `MenuAddOnOptionObserver::created()` start its history with a `count` movement. **Never pass `stock_quantity` to `update()` on a saved row.**

## None left is sold out, and only a restock lifts it
- **Item:** `MenuItemObserver::pairStockWithAvailability()` marks it `OutOfStock` when its count reaches 0 while `Available`, and `Available` again only when a saved item's count goes *up* while `OutOfStock`.
  - `TemporarilyUnavailable` is never touched, however much stock arrives.
  - An order only lowers a count, so it never lifts anything.
  - The database says the first half too: `menu_items_none_left_is_not_available`.
  - Because the availability itself is written, every guest query that already filters on it (`orderableValues()`, `scopeOrderable()`) hides a sold-out item with no change.
- **Option:** `is_available` stays the admin's own switch. `MenuAddOnOption::scopeAvailable()` also hides an option counted down to 0. Flipping the switch instead would turn on, at the next restock, an option an admin had switched off by hand.
- **Combo:** `Guest\MenuController` leaves out a combo holding a counted item with none left.

## Placing an order
`App\Actions\Orders\PlaceOrder` has **two callers**, and they are the same code path with one difference between them:

- `POST /menus/{menu}/orders` — `guest.menus.orders.store`, `throttle:10,1`, no account — is `Guest\PlaceOrderController`. **The guest app does not call it yet.**
- `App\Filament\Tenant\Pages\TakeOrder`, the panel's counter, where staff take an order at the desk or over the phone. It passes `allowOutsideHours: true`; nothing else does. See `.ai/rules/order-taking.md`.

1. **Refused before stock is touched** with `OrderRefused` (422, `reason` from `App\Enums\OrderRefusal`) when:
   - the tenant is closed (`OrderRefusal::StoreClosed`, from its weekly opening hours — `.ai/rules/app.md`)
   - the menu is outside its service window
   - **both of those are waived by `allowOutsideHours`**, and only those two: a staff order past closing is still refused a sold-out line, a broken group or a stock shortage
   - any line is not `ok` in `PriceBasket` — every priced line comes back as `lines`
2. **One transaction:** the order row, then `ApplyStockChanges` with the lines' `StockDemand`, then the lines, their choices and the charges copied in.
3. **Short under the lock:** `InsufficientStock` rolls the order back with it and renders 422 as `{message, reason: "insufficient-stock", shortages: [{type, id, requested, available, lineKeys}]}`.
   - "3 asked for, 1 left" is a shortage with `requested: 3, available: 1`.
   - The server's count needs no correcting. `available` is what the phone brings those lines down to, which is the PWA's job when it arrives.
4. **Placed:** 201 `{orderId, total}`.

`PriceBasket` answers the same `shortages` shape without a lock — a reading, not a hold — beside line statuses it leaves unchanged, so the basket sheet reads the priced basket exactly as before.

**An order is a copy.**
- `orders` keeps its totals as priced, and its GST as levied: `is_union_territory` (what the state's half was called), `tax`, and the `cgst` / `sgst` the two of which add up to it.
- `orders.location_name` is a translated `jsonb` copy of where it went, beside a `nullOnDelete` `location_id`, so renaming or deleting Room 204 never rewrites an order delivered there. A guest who typed free text instead of picking stores it under the default locale. See `.ai/rules/locations.md`.
- `order_lines` and `order_charges` each keep their own `taxable_value`, `tax_rate` and split, and each keeps a copy of its `hsn_sac_code` — an item's, a combo's or a charge's own where it states one — because a tax invoice names a code per line and the source may be recoded or deleted later. See `.ai/rules/actions-menus.md`.
- `order_lines`, `order_line_choices` and `order_charges` keep names as `jsonb` in every language and money as it was then.
- Their keys to the menu are `nullOnDelete`, so deleting or renaming an item never rewrites an order.
- PlaceOrder sets `tenant_id` on every order row itself; no observer does, because nothing else writes them.

The reads of placing an order do not grow with its lines (`PlaceOrderTest`); the writes do, one per line and choice.

## Changing an order before the kitchen has it
`App\Actions\Orders\ReviseOrder` replaces what is on a placed order — its lines, its charges, its totals — **keeping its number**. `App\Actions\Orders\AcceptOrder` is what closes that window: once an order is `OrderStatus::Accepted` somebody is cooking to its lines and it refuses.

- Refused, under the lock, unless it is still open to changes **and** no live payment stands against it — changing a total under money already recorded would leave the two disagreeing, the same rule and the same reason as cancelling's.
- Priced first by the same `PriceBasket`, and refused whole if a line no longer stands.
- **Everything it was holding goes back** as `StockMovementReason::OrderRevised`, read from its own movements, and then the new basket takes what it needs as a fresh `OrderPlaced`. Both rows stay, so the history reads as what happened — and `CancelOrder` nets them (`StockMovementReason::heldByOrderValues()`) rather than summing the takes alone.
- Running short rolls the whole thing back: an order is never left half-changed.
- `App\Actions\Orders\CopyBasketOntoOrder` writes the lines, choices and charges for **both** PlaceOrder and ReviseOrder. It lived inside PlaceOrder until the second caller arrived; an order is a copy however it came to be one.

## Cancelling reverses the order's own movements
`CancelOrder`:
- locks the order, and refuses one already cancelled (`LogicException`); an **accepted** order is still cancellable, because accepting is not finishing
- **refuses one a live payment still stands against**, naming it: staff void the payment first, which stops stock coming back while money sits recorded against an order that no longer exists (`.ai/rules/payments.md`)
- adds back, per row, the sum of that order's `order-placed` movements — never a recomputation from its lines, because a combo's contents or an option may have changed since
- gives nothing back to a row nobody counts any more
- sets `status` to cancelled together with `cancelled_at` (the observer's job now that the CHECK constraint is gone)

## A form never writes back a count it did not change
Filament saves every field a form holds, and the options repeater saves every row. A count written back as the form opened would undo every order placed while it was open. So `App\Filament\Schemas\StockFields` keeps the count the form opened with, `stock_quantity_loaded`, hidden beside the input, and writes an existing row's count only when the two differ — under the lock, through `applyIfChanged()`.

- **Items:** `MenuItemForm::fill()` fills it. Edit actions use `->using(fn ... => MenuItemForm::update($record, $data))`, which saves the item without its count and then applies the count. Creates use `MenuItemForm::storeNew()`.
- **Options:** Filament hands `mutateRelationshipDataBeforeSaveUsing` the option as `$record`, and `MenuAddOnGroupForm::storeExistingOption()` pulls the count out and applies it the same way. The hidden field shares a `Group` with its input, so it draws no table cell of its own (`.ai/rules/filament.md`).

`StockManagementTest` takes stock from an open form and saves it, for an item and for a group's options.

## In the panel
- **Item form:** a Stock section under Tax — "In stock", placeholder "Not tracked".
- **Items page, and a category's items table:**
  - an In stock column: a dash when not counted, red at none
  - a Stock tracked filter (items page)
  - two row actions from `App\Filament\Tables\StockActions`:
    - **Adjust stock:** Add is a restock and never overwrites what orders took; Set count is a count. An item nobody counts offers only Set count, which is how counting starts. Needs `update`.
    - **Stock history:** the 50 newest movements, the order number or the person beside each. Needs `view`.
- **Add-on group options table:** an In stock column.
- **Orders** (`OrderResource`): the list, one order's page, **Cancel order** (`OrderPolicy::cancel()`, which is `order.manage`) and a **New order** header action linking to the counter. Nothing is **edited or deleted** — `OrderPolicy` answers false to both, because what was ordered is a record rather than a draft — but `OrderPolicy::create()` is `order.create` now that staff take orders themselves. It answered false while ordering was the guest app's alone. See `.ai/rules/order-taking.md`.
- The same page's **floor** layout, switched to from a tab above the table: a card per location with what is open at it. And **Take order** (`App\Filament\Tenant\Pages\TakeOrder`, reached from a floor card rather than from the sidebar): the counter.

## Not built yet
- The guest app placing orders and reading `shortages`. The API takes `locationId` and `settlement`; nothing on the phone sends them yet. **The panel does** — `TakeOrder` sends both.
- Order statuses between placed and cancelled, and order numbers.
- Assigning a guest to a room or table (`.ai/rules/locations.md`).
- Low-stock alerts and a daily reset of counts.
- **Idempotency.** A phone retrying the POST would place a second order. This has to be solved before the app places orders.

**Payments are built** — see `.ai/rules/payments.md`. `payments` and `order_payments` record what staff actually took and which orders it cleared, and `orders.settlement` carries the guest's intent to pay now or add it to the bill.

Tests:
- `tests/Feature/Tenant/PlaceOrderTest.php`
- `tests/Feature/Tenant/TakeOrderTest.php`
- `tests/Feature/Tenant/OrderFloorTest.php`
- `tests/Feature/Tenant/StockManagementTest.php`
- `tests/Feature/Tenant/OrderManagementTest.php`
- `tests/Feature/Tenant/BasketPriceTest.php`
- `tests/Feature/Tenant/PaymentTest.php`
- `tests/Feature/Tenant/CheckoutTest.php`
- `tests/Feature/Tenant/LocationManagementTest.php`
- `tests/Feature/Tenant/PaymentDeviceManagementTest.php`
