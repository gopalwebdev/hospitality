---
paths:
  - app/Models/Location.php
  - 'app/Filament/Tenant/Resources/Locations/**'
  - 'app/Actions/Locations/**'
  - app/Observers/LocationObserver.php
  - app/Enums/LocationKind.php
---

# Locations

## One generic module, never separate Rooms and Tables
`locations` is where an order goes. The project owner asked for "Rooms for hotels and Tables for restaurants" and the answer is **one** table, one resource, one policy, with `App\Enums\LocationKind` as the only place a kind of place is named — the same shape as `TenantType`, and for the same reason as the wording rule in `.ai/rules/general.md`: this is one product for hotels, restaurants and hospitals.

Two tables were considered and rejected. They would have duplicated the form, the table, the policy, the factory and the tenant scoping, and given `orders` two nullable foreign keys where exactly one had to be set — a pairing rule with nothing to enforce it now that CHECK constraints are gone (`.ai/rules/migrations.md`).

## A Zone is a grouping, and nothing is ever delivered to one
`LocationKind` has four cases and **two questions**, each of which is the single place its answer lives:

- **`isDeliverable()`** — Room, Table and Area are places an order can go. **Zone is not.** A zone is "Floor 2", "Terrace", "North wing": it groups other locations and is never a destination. `deliverableValues()` is what a query filters on (the `ItemAvailability::orderableValues()` precedent), so a case added later cannot leave a query behind.
- **`canHoldChildren()`** — Zone only. Nothing anywhere compares against `Zone` by name; both the observer and the form ask these two methods instead.

`Location::scopeDeliverable()` is the only way a "where can this order go" list should be built. It is read by the guest API's location lookup (`Guest\PlaceOrderController`), the orders table's location filter, and the Settle action. A guest naming a zone's id gets a 404, exactly as if they had named one that does not exist.

## Two levels, no more — the second self-referencing tree in this schema
`locations.parent_id` is nullable and self-referencing, capped at two levels. This is deliberately the **same shape** as `menu_categories.parent_id` (`.ai/rules/models.md`), so there is one pattern here rather than two.

`LocationObserver` is the **only** guard — every CHECK constraint in this schema was dropped — and it refuses:

- a row as its own parent
- a parent that itself has a parent (a third level)
- a parent belonging to another tenant, through `App\Actions\Tenants\InheritParentTenant`
- a parent that is not a Zone (`canHoldChildren()`)
- a Zone that has been given a parent

It returns early when neither `parent_id` nor `kind` is dirty, so rearranging a list costs no query and reads no column it was not given. **`kind` is in that check as well as `parent_id`**, and that is not decoration: editing a nested room and switching its kind to Zone hides the parent select, Filament does not dehydrate a hidden field, and the stale `parent_id` would otherwise reach `saving()` with only `kind` dirty. `LocationForm` also states that rule as a `->rule()` closure on the kind select, so it surfaces as an inline validation message rather than as a `LogicException` the panel renders as a 500. The observer stays as the backstop for anything that writes around the form.

## A tenant's type decides which kinds it is offered, and never rewrites a row
`TenantType::locationKinds()` is what `LocationForm` offers: Hotel and Hospital get `[Room, Area, Zone]`, Restaurant gets `[Table, Area, Zone]`. A hotel is never invited to file something as a Table. `defaultLocationKind()` is what the select preselects, so staff adding a floor of rooms type a name and nothing else.

**An existing row keeps its kind when its tenant's type changes.** Nothing migrates, nothing is rewritten, and the form adds the record's own kind to the option list so a row created under the old type can still be opened and edited. This is the first thing `tenants.type` actually decides — see `.ai/rules/enums.md`, whose "decides nothing yet" section this replaced.

## An order keeps a copy of where it went
`orders.location_id` is `nullOnDelete` and `orders.location_name` is a translated `jsonb` **copy**, exactly as `order_lines.name` copies an item's. Renaming Room 204, or deleting it, never rewrites an order that was delivered there.

Free text still works: a tenant that has set up no locations at all, or a guest who typed rather than picked, stores `[Locale::default()->value => $text]` — the same shape `PlaceOrder::copyCharges()` uses for a charge with no row. A picked location wins over a typed label when both arrive.

## Adding many at once, and what is not built
`App\Actions\Locations\CreateLocationRange` makes "Room 101" through "Room 120" in one transaction, skipping names the tenant already has (matched on the English name, like every uniqueness check here) and refusing a blank prefix, a backwards range or more than 500 rows. It drives the **Add several** header action, which catches its `LogicException` and shows a danger notification rather than a 500.

**Assigning a guest to a room or table is a later feature.** It is not built, and `locations` carries nothing about occupancy — that is a fact about a stay, not about a room. `code` ("204", "T5") and `capacity` (beds, seats) are here for it, and `locations.id` is the intended anchor for whatever table holds an assignment. Do not put a `status` or an `occupied_by` column on `locations` when it arrives.

Tests: `tests/Feature/Tenant/LocationManagementTest.php`, and the checkout flow in `tests/Feature/Tenant/CheckoutTest.php`.
