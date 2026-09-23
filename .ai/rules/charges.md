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

## A charge is its own supply, taxed at its own code or the tenant's default — never an item's
`charges.tax_rate` and `charges.hsn_sac_code` are **nullable**, exactly like an item's, and the form's Tax section is the same three controls as `MenuItemForm`'s: `PricingFields::taxCodePicker()`, `::taxRatePercentage()` (disabled, filled by the picker) and `::hsnSacCode()` (disabled, filled by the picker). Picking a code copies its rate and number on and lets go — correcting the catalogue later never reprices a charge already saved (`.ai/rules/tax-codes.md`).

**Blank means "the tenant's own rate," never an item's or a combo's.** `Charge::taxRate(int $tenantRate): int` returns `$this->tax_rate ?? $tenantRate` — the same shape as `MenuAddOnOption::taxRate()` — and `PriceBasket::charges()` calls it instead of using `$tenantRate` directly. This is deliberate and worth stating plainly because it is easy to get backwards: a charge is consideration for the supply as a whole, not for any one line on it, so nothing about which item a guest ordered ever decides what a service charge is taxed at.

**`tenant_settings.tax_overrides_item_rates` — "Use this one rate for every item, ignoring item codes" on the Settings page — has no effect on a charge, on or off.** It is read in exactly one place, `HasPricing::taxRate()`, which only `MenuItem` and `MenuCombo` use; `Charge::taxRate()` does not take an `$tenantOverrides` argument at all. A charge with no code of its own always falls back to the tenant's plain rate (the same `cgst_rate + sgst_rate` the override toggle edits), whatever the toggle says — which is also why `Settings::halfRate()` keeps those two fields `dehydrated()` while the toggle is off: they are still a charge's fallback even when they have stopped being any item's rate. Pinned by `GstSplitTest`'s `'never lets "use this one rate for every item" reach a charge'`.

`order_charges` copies the resolved `tax_rate` and the charge's `hsn_sac_code` the same way `order_lines` copies an item's, because the charge may be recoded or deleted before an invoice is raised.

A charge is added to bills from exactly the menus it lists. An "On every menu" switch (`applies_to_all_menus`) existed, and the project owner had it removed. So a new charge starts with every menu picked, and a menu created later is on no existing charge until it is picked there. The seeder puts a charge that names no menus on all of them. `ChargePolicy` is `settings.manage` throughout. Pairing rules: `.ai/rules/models.md`. Tests: `tests/Feature/Tenant/ChargeManagementTest.php`, and the tax-rate behaviour in `tests/Feature/Tenant/GstSplitTest.php`.
