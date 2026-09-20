---
paths:
  - 'app/Filament/Tenant/Resources/Charges/**'
---

# Charges

## Charges are a list with its own page, limited per menu
`ChargeResource` lists what is added to a bill beyond the price — service charge, packing charge, room-service fee — created and edited in modals on `ListCharges` and dragged into the order a guest reads them. It replaced two fixed switches on the Settings page.

`ChargeForm::storeValue()` / `::fillValue()` turn a typed % or ₹ into `rate` / `amount`; the hidden number is not saved and `ChargeObserver` clears it. The menus are a multi-select bound to `menus()`:
- **Options:** `MenuCategoryForm::menuOptions()`, memoized and this tenant's.
- **Validation:** a rule refuses any other id, because `charge_menu` has no tenant.

A charge is added to bills from exactly the menus it lists. An "On every menu" switch (`applies_to_all_menus`) existed, and the project owner had it removed. So a new charge starts with every menu picked, and a menu created later is on no existing charge until it is picked there. The seeder puts a charge that names no menus on all of them. `ChargePolicy` is `settings.manage` throughout. Pairing rules: `.ai/rules/models.md`. Tests: `tests/Feature/Tenant/ChargeManagementTest.php`.
