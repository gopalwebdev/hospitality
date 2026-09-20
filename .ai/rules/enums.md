---
paths:
  - 'app/Enums/**'
  - app/Enums/Role.php
  - app/Enums/TenantType.php
---

# Enums

## Permission categories come from the name, not a column
`App\Enums\PermissionGroup` files every permission under a category, and the mapping lives in exactly one place: each case's `subjects()` list, naming the subject half of `subject.ability`. `forPermissionName()` reads it, and the permissions table filter has to ask the same question in SQL, so it reads it too — via `everySubject()` for the Other bucket, which is whatever no group claims.

This is why a permission added from the panel is categorised without anything being declared for it. Adding a permission with a new subject means adding that subject to a group, or it lands in Other.

`PermissionGroup::ProductTeam` and `Permission::productTeamOnlyValues()` must stay in step — one decides where a permission is shown, the other whether a tenant may be offered a role holding it. A test in PermissionManagementTest pins them together.

## Four kinds of account; a role's name is binding, its permissions are not
There are exactly four kinds of account: the product team (`users.is_admin`, not a role) plus three Spatie roles — `owner` runs one tenant, `staff` works in it, `guest` orders there. Do not add a fourth role without asking; `manager` and `customer` were deliberately removed and remapped (manager→admin, customer→guest), and `admin` was later renamed `owner` so it never shares a name with the platform admin (`users.is_admin`).

What a role *grants* is not owned by the code. `App\Enums\Role::permissions()` is a starting point the seeder writes only on the run that first creates the role — re-running never reverts it, because after that the product team edits permissions from the panel. Only the **name** is binding, because code calls `hasRole('admin')`; the name is `disabled()` in RoleForm for built-in roles and `RoleObserver` (wired in `Role::booting()`) throws on a rename or delete.

## Availability is a reason, not a boolean
`App\Enums\ItemAvailability` replaced a boolean on `menu_items` and `menu_combos` because "off the menu" has a reason worth carrying, and because nothing here is ever hard-deleted to hide it. `isOrderable()` and `orderableValues()` are the only places the distinction between "showing" and "orderable" is made, so a fourth case cannot leave a query behind.

`OutOfStock` is also written for an admin: a counted item reaching none left is marked out of stock, and a restock marks it available again — never `TemporarilyUnavailable` (`.ai/rules/inventory.md`).

## Diet is a mark, and a service request has none
`App\Enums\Diet` (Vegetarian, Vegan, Egg, NonVegetarian) is the veg / vegan / egg / non-veg mark. **An item carries a list of them**, not one: `menu_items.diets` is a `jsonb` array cast with `AsEnumCollection`, because most vegetarian items are vegan as well and one choice made a tenant pick which of the two to say.

**Vegan is its own case, not a kind of vegetarian**: it is a stricter claim — no dairy, no honey — and in a country where most vegetarian cooking uses ghee, curd and milk, it cannot be read off the green square. FSSAI's Vegan Foods Regulations of 2022 give it a separate mark for the same reason. It is teal with a leaf rather than a second green (`Diet::color()` returns `teal`, registered in `TenantPanelProvider`), because two cues beat one at the size these marks are read at.

Two methods carry the rules, and both are the only place they are stated:

- **`Diet::goesWith()`** — which marks may sit together. Every mark but vegan replaces the others; vegan sharpens vegetarian rather than contradicting it, so `Vegetarian + Vegan` is the only pair there is. `MenuItemForm`, `MenuItemObserver` and the `menu_items_diets_are_consistent` constraint all read it, and that constraint is **built from the enum** in the migration, so a case added later cannot leave the database accepting what the form refuses.
- **`Diet::strictest()`** — the one mark a guest reads, by `strictness()` (vegan, vegetarian, egg, non-veg). `MenuItem::dietMark()` wraps it, and the guest payload sends that alone: the panel records everything true, the menu shows one square per item (`.ai/rules/js.md`). `strictness()` is a `match`, so a new case has to say where it sits rather than inherit a position from the order of the cases.

It replaced an enum whose name tied the menu to one kind of business, and whether an item is a service request is **not** a case here or an enum of its own: an `ItemKind` enum was built and replaced by the `menu_items.is_service_request` boolean on the project owner's instruction. The pairing — no marks at all exactly for a service request — is in `.ai/rules/models.md`.

## A charge is a share of the bill or a fixed sum
`App\Enums\ChargeCalculation` is `Percentage` or `FixedAmount`, and `valueColumn()` is the single place that says which column holds the number (`rate` or `amount`) — the same shape as `HomeTileAction::targetColumn()`. `ChargeObserver` and `ChargeForm` both read it. A third calculation means a case, a column, a line in `valueColumn()`, and an edit to `charges_value_matches_calculation`.

## A tax rate is a number, not an enum — this was tried and reverted
GST rates are **not** a fixed value set, and modelling them as one was a mistake worth recording. `App\Enums\TaxRate` existed briefly with cases for the 0/5/12/18/28 slabs; India's GST 2.0 reform of 22 September 2025 collapsed those to 0/5/18 plus a 40% demerit rate, so the enum was wrong on the day it was written. Rates also vary by choice, not just by law — a standalone outlet may elect 5% without input tax credit or 18% with it.

So `tax_rate` is a plain nullable integer on `menu_items` and `menu_combos` — not on add-on options, which are taxed at their item's rate. On `tenant_settings` it is two non-nullable integers, `cgst_rate` and `sgst_rate`, added up by `TenantSetting::taxRate()`: GST on an intra-state supply is levied as two halves and an invoice has to show both. A tenant types the percentages its accountant gives it.

Basis points rather than a percentage float, though — 5% is `500`. That keeps every rate an exact integer, exactly as money is an exact integer in minor units, so nothing between the database and a payment provider ever sees a float. `App\Filament\Schemas\PricingFields` is the only place a typed percentage becomes basis points and back, so the rounding happens once; `TenantSetting::BASIS_POINTS_PER_WHOLE` is the unit. The columns say `tax_rate`, `rate`, `cgst_rate` and never name the unit (`.ai/rules/migrations.md`).

`::DEFAULT_TAX_RATE_BASIS_POINTS` is **0**, and that is the point: no tax information is hardcoded, so a tenant charges nothing until it says what it charges.

## How GST is levied is a tenant's statement, not a lookup
`App\Enums\GstTreatment` — `IntraState` (CGST + SGST), `UnionTerritory` (CGST + UTGST), `InterState` (IGST) — is on `tenant_settings.gst_treatment`, chosen on the Settings page, and copied onto every `orders.gst_treatment` when a bill is placed.

`isSplitInHalves()` is the one question anything pricing asks; `stateTaxLabel()` is what the invoice calls the state's half. UTGST rides the SGST columns, because the money is identical and only the wording differs.

**It is not worked out from where the tenant is.** A `GstStateCode` enum listing all 37 state codes and which union territories have a legislature was written and deleted: that is tax policy baked into the code, and the project owner's instruction is that a tenant states it. A tenant that moves, or whose accountant disagrees, changes a select.

## A tenant's type is data, and decides nothing yet
`tenants.type` is `App\Enums\TenantType`: required on TenantForm with no default, editable by the product team, shown and filtered on in the tenants table, and CHECK-constrained as `tenants_type_is_known`, which its migration builds from the enum's cases. No copy varies by it — the application says "tenant" to everyone (`.ai/rules/lang.md`), and the enum's cases are the only place a kind of business is named.

When a feature first differs by type, put the difference on the enum as a method per question (like `HomeRowLayout`), never string comparisons at the call site — and revisit whether the type may still be edited once data depends on it.

## A menu block's type says what it is, and whether every menu has one
`App\Enums\MenuRailType` (`Featured`, `Combos`) is the `type` of a `menu_rails` row. It replaced a `MenuRail` enum of the same two cases whose job was naming a `position` column on `menus` for each. `isOnEveryMenu()` is the one question that changes how a type is read: true means exactly one per menu with no row until it is placed (`Menu::readingOrder()` fills it in at 0), false — for a kind a menu may hold several of, such as a banner — means only its rows exist. `label()` is what the panel calls it. Adding a case is covered in `.ai/rules/menus.md`; `menu_rails_type_is_known` is rebuilt from the cases by `migrate:fresh`.

## Orders and stock carry four small enums
- **`OrderStatus`:** `Placed`, `Cancelled`. The steps staff move an order through arrive with the screens that move it; `orders_status_is_known` is built from the cases.
- **`OrderLineType`:** `Item`, `Combo` — the same words as `PriceBasket::ITEM` / `COMBO`, and which of `order_lines.menu_item_id` / `menu_combo_id` a line may fill (`order_lines_key_matches_type`).
- **`StockMovementReason`:** `Restock`, `Count`, `OrderPlaced`, `OrderCancelled`, with `label()` and `color()` for the history table.
- **`OrderRefusal`:** why an order was refused, sent to the guest app as `reason` with `message()` from `lang/en/guest.php`. `InsufficientStock` is a case here too, so a refusal always answers with the same field. `StoreClosed` replaced `NotAcceptingOrders` when the switch it named became weekly opening hours.

## A weekday is an enum, and hours repeat weekly
`App\Enums\Weekday` (Monday…Sunday) is the `weekday` of a `tenant_opening_hours` row. `week()` is the week in the order it is read and worked, Monday first, and is what the Settings page and the seeder iterate; `on(CarbonImmutable)` names the day a moment falls on, and `previous()` exists for a window that runs past midnight. `tenant_opening_hours_weekday_is_known` is built from its cases.

Nothing here names a date. A tenant says "closed on Mondays", and a calendar of dated holidays would be a different feature (`.ai/rules/app.md`).

See `.ai/rules/inventory.md`.
