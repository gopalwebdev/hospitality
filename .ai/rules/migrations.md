---
paths:
  - 'database/migrations/**'
---

# Migrations

## One migration file per table, edited in place
Standing instruction from the project owner: every table has exactly **one** migration, `create_<table>_table`, and a change to a table is an edit to that file — never a new `add_`, `alter_` or `rename_` migration. The history was squashed to this shape, `0001_01_01_000000_create_tenants_table.php` onwards, numbered in foreign key order. A new table takes the next number (rename the timestamped file `make:migration` writes). Laravel's multi-table stubs (users and sessions, cache and cache_locks, jobs, job_batches and failed_jobs) and Spatie's five permission tables are split one table per file as well.

The three add-on tables end at `000028`. From there:

| | |
| --- | --- |
| `000029` | locations |
| `000030` | payment_devices |
| `000031` | orders |
| `000032` | order_lines |
| `000033` | order_line_choices |
| `000034` | order_charges |
| `000035` | stock_movements |
| `000036` | tenant_opening_hours |
| `000037` | tax_codes |
| `000038` | payments |
| `000039` | order_payments |

**Renumbering is expected, and it has happened twice.** When `menu_item_additions` was deleted, everything after it moved down one. When `locations` and `payment_devices` arrived they had to be created *before* `orders`, which now carries a foreign key to `locations`, so the seven files from `orders` to `tax_codes` each moved up two. Nothing outside this rule file names a migration by number, which is what makes that safe — keep it that way.

The consequence is deliberate, and "for now": a database is rebuilt with `php artisan migrate:fresh --seed` after a schema change rather than migrated forward. Revisit this before anything runs with data worth keeping, because at that point a change needs a forward migration again.

## No indexes, for now
Also the project owner's instruction: no table carries an index beyond its primary key — no `->index()`, no `->unique()`, no composite unique, no expression index, on the framework and Spatie tables too. Foreign keys stay, and Postgres builds no index for one. CHECK constraints are gone entirely — see below.

What that moved out of the database, and where it went:

- **Uniqueness** — a name per menu or category, a charge's name per tenant, a tenant's slug, an account's email, an item once per combo, an add-on group once per item — is refused by the forms and nowhere else. Code that writes around a form can store a duplicate.
- **A child row on its parent's tenant** was a composite foreign key on `(parent_id, tenant_id)`, which needs a unique index to reference. It is now `App\Actions\Tenants\InheritParentTenant`, called from each child's observer — see `.ai/rules/models.md`. A raw `DB::table()` write goes around it. `charge_menu` has no tenant at all; the charge form is what keeps another tenant's menu out.
- **A sub-category on its parent's menu** was the composite `(parent_id, menu_id)` key, whose `ON UPDATE CASCADE` carried a branch across when its category moved menus. `MenuCategoryObserver` now refuses the mismatch, and `MoveCategoryToMenu` moves the sub-categories itself.

When indexes come back, they go in the table's own migration.

## Strict schema, enums for fixed value sets, light normalization
Columns declare their real type and nullability — no catch-all strings, no nullable-by-default. Every relationship is a real foreign key with an explicit onDelete.

Any fixed set of values is a PHP backed enum in app/Enums/ (TitleCase cases) cast on the model, not a loose string column. The enum owns its own behaviour — see Role::permissions() and FilamentPanel::path(). A plain yes-or-no stays a boolean: whether an item is a service request is `menu_items.is_service_request`, and an enum built for it first was replaced.

Normalize to roughly 3NF and stop: pull repeating groups into their own table with a pivot (tenant_user, charge_menu), but do not shred simple value objects into tables for the sake of it.

## Money is an integer in the minor unit, and the column is named plainly
Never a float or a decimal string: every monetary column is an integer holding the smallest unit of its currency, so ₹249.50 is stored as `24950`. Arithmetic stays exact and no rounding creeps in between the database and a payment provider. The currency itself is on `tenant_settings.currency`, cast to `App\Enums\Currency`.

**The column is named for what it holds, not for its unit.** `price`, `amount`, `subtotal`, `tax`, `total` — not `price_minor_units`. Every rate is the same: `tax_rate`, `rate`, `cgst_rate`, in basis points (5% is `500`). This reverses an earlier instruction to name the unit in the column, which the project owner found unreadable once a table carried a dozen of them; the unit now lives in the model's `@property` docblock and here.

What that costs, and where the cost is paid: nothing at a call site says "this is paise", so a stray `/100` is easier to write. Two things hold the line — **conversion happens in exactly one place per kind**, and those functions still name the unit (`Currency::toMinorUnits()` / `::toMajorUnits()`, `PricingFields::toBasisPoints()` / `::toPercentage()`, `money.ts`'s `minorUnits` parameter). Do not rename those. And a typed form field now **shares its name with its column**, differing only in unit — `price` is rupees in the form and paise in the row — so `PricingFields::store()` / `::fill()`, `ChargeForm::storeValue()` / `::fillValue()` and `MenuAddOnGroupForm::storeOption()` convert **in place** and unset only the form-only keys. Unsetting a converted key there silently stored zero, which is exactly what happened on the rename.

One exception to the plain name: `orders.charges_total`, because `Order::charges()` is already a relation.

## A phone number is two columns: calling code and national number
Never one free-text string. The calling code goes in its own column cast to App\Enums\CountryCallingCode (backing values carry the plus, '+91', which keeps them strings when used as array keys), and the national number goes in a column of its own holding digits only — ten of them for India. Size number columns to CountryCallingCode::longestMobileNumberLength(). Tenant::dialablePhone() puts the two halves back together for display; nothing else should concatenate them by hand. See tenants.phone_country_code/phone for the shape.

## A translated column is jsonb
Any text a guest reads is a `jsonb` column holding one key per App\Enums\Locale case, not a string — see `.ai/rules/models.md`. `jsonb` is stored parsed and has the equality and ordering operators plain `json` lacks. A new translated column is `$table->jsonb(...)`.

## No CHECK constraints, and no indexes: the schema states shape, not rules
Standing instruction from the project owner, and it **reverses** the rule that used to sit here ("Rules the database can state, it states"). Every `ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)` was deleted — 23 statements across 21 tables, covering money and positions never being negative, rates in range, an enum's value being one the enum knows, a tile's action matching its destination, an item's diet marks matching its kind, a charge's number matching its calculation, opening hours matching their closed flag, GST parts adding up, and the rest. Do not write a new one.

What a migration declares now is **shape only**: the column, its real type, its nullability, and a plain foreign key. Nothing else.

**Foreign keys keep their delete behaviour.** `cascadeOnDelete()` and `nullOnDelete()` stay exactly as they are, and this is the one thing that was *not* dropped: without them Postgres defaults to NO ACTION, and deleting a menu, a category, an add-on group, a charge or a tenant would fail outright because its children block it. That is delete breaking, not a rule relaxing. Removing them means writing the cascade by hand in PHP first.

**Where the rules went, and what that costs.** They were always stated twice — in the form and the observer as well as the database — so the application still refuses everything it refused before. What is gone is the backstop for a write that goes *around* the model: a raw `DB::table()` insert, a factory with `withoutEvents()`, a seeder taking a shortcut. `MenuItemObserver` is now the only thing keeping an item's diet marks in step with its kind; `MenuCategoryObserver` the only thing refusing a third level or a mismatched menu; `ChargeObserver` the only thing clearing the number its calculation does not name. Tests that used to prove "the database refuses this whatever writes it" now prove the observer does, which is a weaker claim honestly stated (see `MenuManagementTest`).

Keep writing the rule in the model and the form. It is the only place it lives.

## Walk rows in chunks
Code that rewrites existing rows — a seeder, a command — uses `chunkById` and selects only the columns it needs, so a tenant with a long menu costs the same memory as one with a short one. Never `get()` a whole table into an array to loop over it.

## Timestamps are Asia/Kolkata, set from the environment
`APP_TIMEZONE` drives `config/app.php` and `DB_TIMEZONE` is handed to the pgsql connection, so `now()` in PHP and `now()` in SQL agree. Neither is hardcoded anywhere, and `phpunit.xml` pins the same zone so tests behave as production does.
