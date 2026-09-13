---
paths:
  - 'app/Policies/**'
---

# Policies

## strictAuthorization means a missing policy method is a 500, not a default
Both panels run `->strictAuthorization()`, so Filament throws when a policy lacks the method it is asking for rather than quietly allowing access. That catches unauthorised pages, but it also means **adding a table feature can break a page until its policy catches up**.

The one that bites: a hand-arranged table asks for `reorder()`, which is not one of the methods `make:policy` generates. `MenuPolicy`, `MenuCategoryPolicy`, `MenuItemPolicy`, `MenuComboPolicy`, `HomeRowPolicy`, `HomeTilePolicy` and `ChargePolicy` all have it — every one of those lists is arranged by hand, because the order is what the tenant is deciding. Bulk actions likewise need `deleteAny()`.

`reorder()` is also what `reorderTable()` short-circuits on, so a test that only asserts the drag button is hidden proves nothing — call `reorderTable()` directly and assert the positions did not move. That holds for an overridden `reorderTable()` too: keep the `isReorderable()` check first (see `.ai/rules/tables.md`).

When you add a resource or a table capability, write the policy method in the same change and cover the page with a test that actually renders it — a policy check that is never exercised proves nothing.

## ChargePolicy is settings.manage for everything
`ChargePolicy` answers every method — looking included — with `settings.manage`, which a tenant owner holds and floor staff do not. Charges moved off the Settings page onto their own, and the same people change them; do not split them onto `menu.view` / `menu.manage`, which staff and guests partly hold.
