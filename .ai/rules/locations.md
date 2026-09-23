---
paths:
  - app/Models/Location.php
  - 'app/Filament/Tenant/Resources/Locations/**'
  - 'app/Actions/Locations/**'
  - app/Enums/LocationKind.php
---

# Locations

## One generic module, never separate Rooms and Tables
`locations` is where an order goes. The project owner asked for "Rooms for hotels and Tables for restaurants" and the answer is **one** table, one resource, one policy, with `App\Enums\LocationKind` as the only place a kind of place is named — the same shape as `TenantType`, and for the same reason as the wording rule in `.ai/rules/general.md`: this is one product for hotels, restaurants and hospitals.

Two tables were considered and rejected. They would have duplicated the form, the table, the policy, the factory and the tenant scoping, and given `orders` two nullable foreign keys where exactly one had to be set — a pairing rule with nothing to enforce it now that CHECK constraints are gone (`.ai/rules/migrations.md`).

`LocationKind` is `Room`, `Table` and `Area`. Every case is somewhere an order can actually go.

## The list is flat, and a Zone was built and removed
A fourth kind, `Zone`, plus a self-referencing nullable `locations.parent_id`, existed for one revision: rooms grouped under "Floor 1", tables under "Terrace", capped at two levels by a `LocationObserver` that mirrored `MenuCategoryObserver`. **The project owner had the whole thing removed**, and the reasoning is worth keeping because the nesting looks sensible on paper:

- A location list answers "where can this order go". A floor is not an answer to that, so a `Zone` row had to be excluded from every destination query — which meant an `isDeliverable()` question, a `deliverableValues()` list, a `scopeDeliverable()`, and a filter on the guest API, the orders filter and the Settle action. Four places had to remember a kind that was never a real destination.
- It brought a whole observer whose only job was policing a hierarchy nobody had asked to browse by, plus a `canHoldChildren()` question and a validation rule on the kind select to stop a nested row being switched to a Zone.
- Grouping a long list is what a **filter and a search** are for, and the table already has both.

So: no `parent_id`, no `LocationObserver`, no `Zone`. If grouping by floor is ever genuinely wanted, reach for a filter before reaching for a tree.

## A tenant's type decides which kinds it is offered — in the form *and* the tabs
`TenantType::locationKinds()` is Hotel and Hospital → `[Room, Area]`, Restaurant → `[Table, Area]`. A hotel is never invited to file something as a Table. `defaultLocationKind()` is what the form preselects, so staff adding a floor of rooms type a name and nothing else.

**`ListLocations::getTabs()` reads the same list**, and that is the point rather than an incidental. It used to iterate `LocationKind::cases()`, so a hotel's locations page drew a permanently empty **"Table 0"** tab and a restaurant drew "Room 0". Iterating the enum was the safe-looking choice — "a kind added later cannot leave a tab behind" — but the list it should iterate is the tenant's own answer, which has that property too. Anywhere else that offers a kind must read `locationKinds()`, not `cases()`.

**An existing row keeps its kind when its tenant's type changes.** Nothing migrates and nothing is re-filed. `LocationForm` adds the record's own kind to the option list so a row created under the old type can still be opened and edited. This is the first thing `tenants.type` actually decides — see `.ai/rules/enums.md`, whose "decides nothing yet" section this replaced.

## An order keeps a copy of where it went
`orders.location_id` is `nullOnDelete` and `orders.location_name` is a translated `jsonb` **copy**, exactly as `order_lines.name` copies an item's. Renaming Room 204, or deleting it, never rewrites an order that was delivered there.

Free text still works: a tenant that has set up no locations at all, or a guest who typed rather than picked, stores `[Locale::default()->value => $text]` — the same shape `PlaceOrder::copyCharges()` uses for a charge with no row. A picked location wins over a typed label when both arrive.

The orders table's location filter is deliberately **not** narrowed to the active locations: it filters orders already placed, and one may name a location switched off since.

## Adding many at once, and what is not built
`App\Actions\Locations\CreateLocationRange` makes "Room 101" through "Room 120" in one transaction, skipping names the tenant already has (matched on the English name, like every uniqueness check here) and refusing a blank prefix, a backwards range or more than 500 rows. It drives the **Add several** header action, which catches its `LogicException` and shows a danger notification rather than a 500.

**Assigning a guest to a room or table is a later feature.** It is not built, and `locations` carries nothing about occupancy — that is a fact about a stay, not about a room. `code` ("204", "T5") and `capacity` (beds, seats) are here for it, and `locations.id` is the intended anchor for whatever table holds an assignment. Do not put a `status` or an `occupied_by` column on `locations` when it arrives.

Tests: `tests/Feature/Tenant/LocationManagementTest.php`, and the checkout flow in `tests/Feature/Tenant/CheckoutTest.php`.
