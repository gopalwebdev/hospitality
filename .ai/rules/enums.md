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

## Diet is a mark, and a service request has none
`App\Enums\Diet` (Vegetarian, Egg, NonVegetarian) is the veg / egg / non-veg mark on `menu_items.diet`. It replaced an enum whose name tied the menu to one kind of business, and whether an item is a service request is **not** a case here or an enum of its own: an `ItemKind` enum was built and replaced by the `menu_items.is_service_request` boolean on the project owner's instruction. The pairing — diet null exactly for a service request — is in `.ai/rules/models.md`.

## A charge is a share of the bill or a fixed sum
`App\Enums\ChargeCalculation` is `Percentage` or `FixedAmount`, and `valueColumn()` is the single place that says which column holds the number (`rate_basis_points` or `amount_minor_units`) — the same shape as `HomeTileAction::targetColumn()`. `ChargeObserver` and `ChargeForm` both read it. A third calculation means a case, a column, a line in `valueColumn()`, and an edit to `charges_value_matches_calculation`.

## A tax rate is a number, not an enum — this was tried and reverted
GST rates are **not** a fixed value set, and modelling them as one was a mistake worth recording. `App\Enums\TaxRate` existed briefly with cases for the 0/5/12/18/28 slabs; India's GST 2.0 reform of 22 September 2025 collapsed those to 0/5/18 plus a 40% demerit rate, so the enum was wrong on the day it was written. Rates also vary by choice, not just by law — a standalone outlet may elect 5% without input tax credit or 18% with it.

So `tax_rate_basis_points` is a plain nullable integer on `menu_items`, `menu_item_additions` and `menu_combos`, and a non-nullable one on `tenant_settings`. A tenant types the percentage its accountant gives it.

Basis points rather than a percentage float, though — 5% is `500`. That keeps every rate an exact integer, exactly as money is an exact integer in minor units, so nothing between the database and a payment provider ever sees a float. `App\Filament\Schemas\PricingFields` is the only place a typed percentage becomes basis points and back, so the rounding happens once; `TenantSetting::BASIS_POINTS_PER_WHOLE` is the unit and `::DEFAULT_TAX_RATE_BASIS_POINTS` the starting point. A charge's rate is basis points for the same reason.

## A tenant's type is data, and decides nothing yet
`tenants.type` is `App\Enums\TenantType`: required on TenantForm with no default, editable by the product team, shown and filtered on in the tenants table, and CHECK-constrained as `tenants_type_is_known`, which its migration builds from the enum's cases. No copy varies by it — the application says "tenant" to everyone (`.ai/rules/lang.md`), and the enum's cases are the only place a kind of business is named.

When a feature first differs by type, put the difference on the enum as a method per question (like `HomeRowLayout`), never string comparisons at the call site — and revisit whether the type may still be edited once data depends on it.

## A menu block's type says what it is, and whether every menu has one
`App\Enums\MenuBlockType` (`Featured`, `Combos`) is the `type` of a `menu_blocks` row. It replaced a `MenuBlock` enum of the same two cases whose job was naming a `position` column on `menus` for each. `isOnEveryMenu()` is the one question that changes how a type is read: true means exactly one per menu with no row until it is placed (`Menu::readingOrder()` fills it in at 0), false — for a kind a menu may hold several of, such as a banner — means only its rows exist. `label()` and `description()` are what the menu page calls it. Adding a case is covered in `.ai/rules/menus.md`; `menu_blocks_type_is_known` is rebuilt from the cases by `migrate:fresh`.
