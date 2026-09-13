---
paths:
  - 'app/Filament/Tenant/Resources/Charges/**'
---

# Charges

## Charges are a list with its own page, limited per menu
`ChargeResource` lists what is added to a bill beyond the price — service charge, packing charge, room-service fee — created and edited in modals on `ListCharges` and dragged into the order a guest reads them. It replaced two fixed switches on the Settings page.

`ChargeForm::storeValue()` / `::fillValue()` turn a typed % or ₹ into `rate_basis_points` / `amount_minor_units`; the hidden number is not saved and `ChargeObserver` clears it. The menus checklist is bound to `menus()` but its options are `MenuCategoryForm::menuOptions()` (memoized, this tenant's) and a rule refuses any other id, because `charge_menu` has no tenant. On every menu the list is emptied by the observer. `ChargePolicy` is `settings.manage` throughout. Pairing rules: `.ai/rules/models.md`. Tests: `tests/Feature/Tenant/ChargeManagementTest.php`.
