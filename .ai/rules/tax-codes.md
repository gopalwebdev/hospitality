---
paths:
  - 'app/Filament/Tenant/Resources/TaxCodes/**'
  - app/Models/TaxCode.php
  - app/Policies/TaxCodePolicy.php
  - database/seeders/TaxCodeSeeder.php
---

# HSN and SAC codes

## The list exists so nobody types a GST rate from memory, and it copies rather than binds
`tax_codes` holds a code, what it covers, and the rate it usually carries. Picking one on the item form **copies** its `tax_rate` and its `code` onto that item and then lets go: `menu_items.tax_rate` and `menu_items.hsn_sac_code` are what a bill actually reads, and nothing reads this table again.

That was the project owner's choice over referencing the row, and the consequences are the point:
- Correcting a code **never reprices** anything already filled in. A slab changing is a panel job, item by item, not a silent repricing of a live menu.
- An item that needs a rate the catalogue does not carry gets a new code added to the catalogue. The two fields are disabled, so the code list is the one place a rate is decided and it stays reviewable.
- Nothing on the pricing path touches this table. `PriceBasket`, `GstSplit` and `PlaceOrder` have never heard of it, and must not learn: a placed order already copies the rate it was invoiced at (`.ai/rules/actions-menus.md`).

**The picker is the only way to set either field.** `PricingFields::taxRatePercentage()` and `::hsnSacCode()` are `disabled()`, on the project owner's instruction: a rate is chosen by naming what is being sold, not typed from memory. A rate the catalogue does not offer is added to the catalogue, which is a page the tenant owns. Both carry `dehydrated()` — Filament leaves a disabled field out of the save by default, which would blank the rate on every edit. There is no placeholder on the rate any more; it used to show the tenant's own, so an empty box read "18" and looked filled in.

`PricingFields::taxCodePicker()` is shared by the item form and the combo form, is `dehydrated(false)` because neither table has a column for it, and `formatStateUsing()` reopens an edit on the code the record was filled from by matching its stored `hsn_sac_code` and `tax_rate`. Clearing it clears both fields, which is how a line goes back to the tenant's own rate. Options come from `taxCodeOptions()` wrapped in `once()` — Filament asks a select for its options several times while building and validating one form, and the duplicate-query guard throws otherwise (`.ai/rules/app.md`).

**An add-on option gets one column, not three.** In the options repeater the picker *is* the whole tax control: a rate and a code beside it would have been two read-only boxes repeating the label already on the select, on a row that was eight columns wide. `MenuAddOnGroupForm::storeOption()` resolves the picked code into `tax_rate` and `hsn_sac_code`; `fillOption()` works the id back out of the pair. Blank means "taxed with the item", which is the ordinary answer.

## A row with no tenant is the shared catalogue
`tax_codes.tenant_id` is **nullable**, and that is the whole design: null is the catalogue `TaxCodeSeeder` writes, offered to every tenant; a row naming a tenant is that tenant's own addition and nobody else is offered it. `TaxCode::availableTo()` is the one query that puts the two together.

Three things follow, and each is load-bearing:

- **Filament's tenancy is turned off on the resource.** `TaxCodeResource::$isScopedToTenant` is `false`, because the panel's own scoping would add `where tenant_id = ...` and hide exactly the half a new tenant needs. `getEloquentQuery()` scopes through `availableTo()` instead. This is the one resource in the tenant panel that scopes itself; do not "fix" it by turning tenancy back on.
- **A new row is stamped by hand.** With tenancy off, Filament will not set `tenant_id`, so `ListTaxCodes`' create action does it through `Filament::getTenant()`. A row created without one would join the catalogue and be offered to every other tenant on the platform.
- **A tenant may read the catalogue and never change it.** `TaxCodePolicy::update()` and `::delete()` refuse a row where `isFromCatalogue()`, because one tenant editing a shared row would reprice every other tenant's next item. The table draws a **Source** badge — "Standard" or "Yours" — since a missing Edit button explains nothing on its own.

There is no platform-panel resource for the catalogue yet: it is seeded, and the product team changes it by editing `TaxCodeSeeder` and reseeding. Adding one is a small follow-up if the catalogue starts changing often.

## The seeded rates are a starting point, not this application's tax policy
This sits deliberately close to the standing instruction that **no tax information is hardcoded** (`.ai/rules/app.md`), so the boundary is worth stating exactly.

What the instruction forbids is the application *asserting* a rate — a default that silently charges what nobody typed, or a list of slabs in code that has to be deployed to be corrected. `App\Enums\TaxRate` was built and reverted for the second reason: GST 2.0 restructured the slabs on 22 September 2025 and the enum was wrong the day it shipped (`.ai/rules/enums.md`).

The catalogue is neither. It is **rows**, editable without a deploy; nothing is applied on its own, since a rate reaches a bill only once someone picks a code on an item and saves it; and the item's rate stays editable, so a stale catalogue row is always correctable where it lands. `TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS` stays **0** — the catalogue changes nothing about a tenant that has said nothing.

Keep it that way. Do not give `tax_codes` a default that applies without being picked, do not make a bill read this table, and treat the seeded rates as data to be reviewed rather than as something the code knows.
