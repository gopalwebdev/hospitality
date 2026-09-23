---
paths:
  - 'app/Policies/**'
---

# Policies

## strictAuthorization means a missing policy method is a 500, not a default
Both panels run `->strictAuthorization()`, so Filament throws when a policy lacks the method it is asking for rather than quietly allowing access. That catches unauthorised pages, but it also means **adding a table feature can break a page until its policy catches up**.

The one that bites: a hand-arranged table asks for `reorder()`, which is not one of the methods `make:policy` generates. `MenuCategoryPolicy`, `MenuItemPolicy`, `MenuComboPolicy`, `HomeRowPolicy`, `HomeTilePolicy`, `ChargePolicy`, `LocationPolicy` and `PaymentDevicePolicy` have it. The menu page's outline asks `MenuCategoryPolicy`; the tables its rows open ask `MenuItemPolicy` (a category's items, the featured items) and `MenuComboPolicy`; the other three lists are arranged by hand on their own tables. `MenuPolicy` had it and lost it when nothing asked any more, because menus are not ordered by hand. Items and combos lost it too while they were rows of the outline, and got it back when they moved into tables of their own. Delete the method once nothing asks for it. Bulk actions likewise need `deleteAny()`.

`reorder()` is also what `reorderTable()` short-circuits on, so a test that only asserts the drag button is hidden proves nothing — call `reorderTable()` directly and assert the positions did not move. That holds for an overridden `reorderTable()` too: keep the `isReorderable()` check first (see `.ai/rules/tables.md`).

When you add a resource or a table capability, write the policy method in the same change and cover the page with a test that actually renders it — a policy check that is never exercised proves nothing.

`OrderPolicy` has a `cancel()` that no generator writes, asked by the cancel action's `->authorize('cancel')`, and answers `create`, `update`, `delete` and `deleteAny` with false outright: orders are placed by guests and never edited in the panel.

## TaxCodePolicy is settings.manage, minus the shared catalogue
`TaxCodePolicy` reads like `ChargePolicy` — `settings.manage` throughout — with one addition: `update()` and `delete()` also refuse a row whose `tenant_id` is null, because that row is the product team's catalogue and every tenant is offered it. A tenant sees it, fills items from it, and cannot change it. See `.ai/rules/tax-codes.md`.

## PaymentPolicy refuses editing and deleting outright
`PaymentPolicy` answers `viewAny`/`view` with `payment.view`, `create` with `payment.record` and `void` with `payment.void` — and `update`, `delete` and `deleteAny` with **false**, the same way `OrderPolicy` does. A payment is a record of money that changed hands: it is voided, never edited or destroyed (`.ai/rules/payments.md`).

`payment.void` is deliberately separate from `payment.record`, and the Staff role holds the second and not the first: floor staff take money, reversing it is an owner's call.

`LocationPolicy` is `location.manage` throughout plus `reorder()`; `PaymentDevicePolicy` is `settings.manage` throughout plus `reorder()`, because a card machine is tenant configuration and belongs with Charges rather than with the floor. The **Settle** action on the locations table is authorised against `payment.record` by a closure rather than by a `LocationPolicy` method — it is a payments capability that happens to be shown on a location row, and `LocationPolicy` has no business naming a payment permission.

## ChargePolicy is settings.manage for everything
`ChargePolicy` answers every method — looking included — with `settings.manage`, which a tenant owner holds and floor staff do not. Charges moved off the Settings page onto their own, and the same people change them; do not split them onto `menu.view` / `menu.manage`, which staff and guests partly hold.
