---
paths:
  - app/Models/Payment.php
  - app/Models/OrderPayment.php
  - app/Models/PaymentDevice.php
  - 'app/Actions/Payments/**'
  - 'app/Filament/Tenant/Resources/Payments/**'
  - 'app/Filament/Tenant/Resources/PaymentDevices/**'
  - app/Enums/PaymentMethod.php
  - app/Enums/PaymentState.php
  - app/Enums/OrderSettlement.php
---

# Payments

## A payment is one transaction, and it settles as many orders as it cleared
`payments` is one real-world event — one card swipe, one UPI scan, one handful of cash — and `order_payments` says which orders it cleared and **by how much**. The relationship is many-to-many with an allocated amount, and that is the whole design:

- A guest adds four room-service orders to their room and pays once on the way out: **one** payment row, one transaction number, four allocations.
- A bill is split across cash and card: **two** payments, both allocated to the one order.

A plain `payments.order_id` was considered and rejected. It turns one card swipe into five rows all repeating the same approval number, and it cannot express a split bill at all. Both cases are ordinary in hospitality.

`order_payments` is a real entity rather than a bare pivot — it carries an amount — so the table is plural, it has an `OrderPayment` model, and `OrderPaymentObserver` takes its `tenant_id` from its payment through `App\Actions\Tenants\InheritParentTenant`. **`tenant_id` is deliberately not fillable on it**; anything writing an allocation lets the observer do it.

## A payment is voided, never deleted
`voided_at`, `voided_by_user_id` and `void_reason`, and `PaymentPolicy` answers `update`, `delete` and `deleteAny` with **false outright** — the `OrderPolicy` shape. This is the "nothing on the menu is hard-deleted to take it off" instinct from `.ai/rules/models.md`, and money deserves it more than a menu item does.

`VoidPayment` locks the payment, refuses one already voided, and **leaves the allocations in place** — the history is the point. Everything that reads money ignores a voided payment, so the order simply owes again.

## What an order has been paid is never a column
There is no `orders.amount_paid`. A cached sum drifts; `charges_total` is a copy of a moment and never changes, which is a different thing entirely.

- **`Order::amountPaid()`** prefers a `withSum` value already loaded for the page and falls back to a query, through the existing `ReadsLoadedCounts` trait — the pattern `.ai/rules/models.md` already settles.
- **`paymentState()`** is `App\Enums\PaymentState` (Unpaid, PartlyPaid, Paid), worked out against the total. Not a column, so it cannot disagree with the money.
- **`scopeUnsettled()` / `scopeSettled()`** are correlated subqueries over `order_payments` joined to live payments.

**Two traps, both of which have already cost an afternoon:**

- **The alias must be exactly `amount_paid`, and the sum must be constrained to live payments.** `Order::amountPaid()` reads the loaded value back under that name. Get either wrong and the payment badge shows a **wrong number with no error at all** — nothing throws, nothing logs. The expression is `->withSum(['paymentAllocations as amount_paid' => fn ($q) => $q->whereHas('payment', fn ($p) => $p->live())], 'amount')`, and `OrdersTable`, `OrderResource` and `LocationsTable` all use exactly it.
- **`scopeUnsettled()` does not exclude a cancelled order.** It answers "owes money", which a cancelled order technically does. Anywhere orders are listed *to be settled*, filter to `OrderStatus::Placed` as well — the orders table's Unsettled filter and `LocationsTable::outstandingOrders()` both do.

## A method names its own device and reference, and what it does not name is cleared
`App\Enums\PaymentMethod` (Cash, Upi, CreditCard, DebitCard) carries the pairing rules, each the single place its question is answered — the `ChargeCalculation::valueColumn()` shape:

- **`deviceKind(): ?PaymentDeviceKind`** — Cash names none, Upi a `QrCode`, both cards a `CardMachine`. `PaymentDevice::scopeForMethod()` reads it, so the Record payment modal offers a card only the machines and UPI only the QR codes.
- **`takesReference(): bool`** — false for cash, true otherwise.

`RecordPayment` **clears** what a method does not use rather than refusing it: a device handed in for a cash payment is simply not the point of cash, not a mistake worth stopping the money for. It **refuses** a device that is another tenant's, inactive, or of the wrong kind — that is a real mismatch.

`payment_devices` exists because a tenant has several machines and several QR codes and the day's takings are reconciled one at a time. Its `name` is a plain string, **not** translated: no guest ever reads "Counter machine 1" (`.ai/rules/models.md` reserves translated `jsonb` for guest-facing text).

## RecordPayment locks, then reads the sums once
The only writer of `payments` and `order_payments`. In one transaction it locks every named order `FOR UPDATE` **in key order** — the `ApplyStockChanges` discipline, so two staff settling at once queue rather than deadlock — and then reads what each has already been paid in **one** query, `amountsAlreadyPaid()`.

That method exists for two reasons, and both matter. Asking each order for its own `amountPaid()` is a query per order **inside a lock**; and it is the *same* query the panel already ran to work the allocations out, which this project's duplicate-query guard refuses outright (`.ai/rules/app.md`). Outstanding is worked out from those sums, never from a value either side had loaded, so two payments racing to settle one order cannot both succeed.

It refuses, writing nothing: an empty or non-positive allocation, allocations that do not add up to the payment's amount, an order that is cancelled or another tenant's, an allocation above what that order still owes, and a mismatched device. Refusals are `App\Exceptions\PaymentRefused` carrying an `App\Enums\PaymentRefusal` reason, rendering 422 — the `OrderRefused` shape. **Every panel action that calls it catches that and shows a danger notification**; a refusal must never reach a panel user as a 500.

`SpreadAcrossOrders` is the checkout arithmetic, kept separate so it has one reason to change: pure, no database, oldest first, each order taking at most what it owes and the one the money runs out on taking the remainder.

## Settlement is the guest's intent; payments are the truth
`orders.settlement` is `App\Enums\OrderSettlement` — `PayNow` or `AddToBill`. It records what the guest chose, **not** whether any money arrived: an order marked PayNow with nothing recorded against it is still unpaid. Its job is the panel's worklist — which orders want a payment taken now, and which are running up a bill to settle at checkout.

`CancelOrder` now **refuses an order a live payment still stands against**, naming it. Staff void the payment first. That is the auditable order to do it in, and it stops stock coming back while money sits recorded against an order that no longer exists.

## In the panel
- **Payments** (`PaymentResource`, under Orders): list and view only, never created or edited here — a payment is born from an order or from Settle. It doubles as the reconciliation page: what was taken today, by what method, on which machine. **Void** lives here and nowhere else.
- **Order page:** a read-only Payments section and the amount outstanding. **Record payment** is a row and page action, visible only on a placed order that still owes something.
- **Locations:** a **Settle** row action — the "check out Room 204" flow — listing that location's outstanding placed orders as a multi-select (never a `CheckboxList`, per the standing instruction in `.ai/rules/filament.md`), all picked by default, then `SpreadAcrossOrders` and `RecordPayment`. It is authorised against `payment.record` by a closure rather than through `LocationPolicy`: it is a payments capability that happens to be shown on a location row.
- **Payment devices** (`PaymentDeviceResource`): behind `settings.manage`, like Charges. Staff take money; they do not add machines.

## A refresh() drops what a page eager-loaded
`Order::refresh()` reloads only bare top-level relation names — it drops **nested dot-path eager loads and any `withSum` aggregate**. `OrderResource::getRecordRouteBindingEloquentQuery()` loads `paymentAllocations.payment.paymentDevice` and the `amount_paid` sum, so a plain `refresh()` after a panel action made the infolist's redraw lazy-load and throw under `Model::shouldBeStrict()`.

`OrdersTable::reloadPayments()` is what both Record payment and Cancel call instead. This bit on the **order's own page only** — the list path loads none of that — which is why `OrderManagementTest` cancels from `ViewOrder` as well as from the table.

Tests: `tests/Feature/Tenant/PaymentTest.php`, `CheckoutTest.php`, `PaymentDeviceManagementTest.php`, `OrderManagementTest.php`.
