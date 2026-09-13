---
paths:
  - 'database/migrations/**'
---

# Migrations

## One migration file per table, edited in place
Standing instruction from the project owner: every table has exactly **one** migration, `create_<table>_table`, and a change to a table is an edit to that file — never a new `add_`, `alter_` or `rename_` migration. The history was squashed to this shape, `0001_01_01_000000_create_tenants_table.php` through `0001_01_01_000025_create_charge_menu_table.php`, numbered in foreign key order. A new table takes the next number (rename the timestamped file `make:migration` writes). Laravel's multi-table stubs (users and sessions, cache and cache_locks, jobs, job_batches and failed_jobs) and Spatie's five permission tables are split one table per file as well.

The consequence is deliberate, and "for now": a database is rebuilt with `php artisan migrate:fresh --seed` after a schema change rather than migrated forward. Revisit this before anything runs with data worth keeping, because at that point a change needs a forward migration again.

## No indexes, for now
Also the project owner's instruction: no table carries an index beyond its primary key — no `->index()`, no `->unique()`, no composite unique, no expression index, on the framework and Spatie tables too. Foreign keys and CHECK constraints stay; Postgres builds no index for either.

What that moved out of the database, and where it went:

- **Uniqueness** — a name per menu or category, a charge's name per tenant, a tenant's slug, an account's email, an item once per combo — is refused by the forms and nowhere else. Code that writes around a form can store a duplicate.
- **A child row on its parent's tenant** was a composite foreign key on `(parent_id, tenant_id)`, which needs a unique index to reference. It is now `App\Actions\Tenants\InheritParentTenant`, called from each child's observer — see `.ai/rules/models.md`. A raw `DB::table()` write goes around it. `charge_menu` has no tenant at all; the charge form is what keeps another tenant's menu out.
- **A sub-category on its parent's menu** was the composite `(parent_id, menu_id)` key, whose `ON UPDATE CASCADE` carried a branch across when its category moved menus. `MenuCategoryObserver` now refuses the mismatch, and `MoveCategoryToMenu` moves the sub-categories itself.

When indexes come back, they go in the table's own migration.

## Strict schema, enums for fixed value sets, light normalization
Columns declare their real type and nullability — no catch-all strings, no nullable-by-default. Every relationship is a real foreign key with an explicit onDelete.

Any fixed set of values is a PHP backed enum in app/Enums/ (TitleCase cases) cast on the model, not a loose string column. The enum owns its own behaviour — see Role::permissions() and FilamentPanel::path(). A plain yes-or-no stays a boolean: whether an item is a service request is `menu_items.is_service`, and an enum built for it first was replaced.

Normalize to roughly 3NF and stop: pull repeating groups into their own table with a pivot (tenant_user, charge_menu), but do not shred simple value objects into tables for the sake of it.

## Money is stored as an integer in the minor unit
Never a float or a decimal string: every monetary column is an integer holding the smallest unit of its currency, so ₹249.50 is stored as 24950. Arithmetic stays exact and no rounding creeps in between the database and a payment provider. Convert to and from a display value at the edge, and name the column so the unit is unmistakable. The currency itself is on tenant_settings.currency, cast to App\Enums\Currency.

## A phone number is two columns: calling code and national number
Never one free-text string. The calling code goes in its own column cast to App\Enums\CountryCallingCode (backing values carry the plus, '+91', which keeps them strings when used as array keys), and the national number goes in a column of its own holding digits only — ten of them for India. Size number columns to CountryCallingCode::longestMobileNumberLength(). Tenant::dialablePhone() puts the two halves back together for display; nothing else should concatenate them by hand. See tenants.phone_country_code/phone for the shape.

## A translated column is jsonb
Any text a guest reads is a `jsonb` column holding one key per App\Enums\Locale case, not a string — see `.ai/rules/models.md`. `jsonb` is stored parsed and has the equality and ordering operators plain `json` lacks. A new translated column is `$table->jsonb(...)`.

## Rules the database can state, it states
Postgres is the only engine, so a rule a CHECK constraint can express is written as one as well as in the model and the form — a raw `ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)` after `Schema::create()`, in the table's own migration. What exists: money, positions and role limits are never negative (Postgres has no unsigned integers, so `unsignedInteger` alone promises nothing); rates are 0–10000 basis points; a combo holds an item at least once; a menu's service window is both times or neither; a category is not its own parent; a tile's action and its destination match; an item's diet is filled exactly when it is not a service request (`menu_items_diet_matches_service`); a charge's number is in the one column its calculation names (`charges_value_matches_calculation`); and a tenant's type and a charge's calculation are ones their enums know.

Two things are deliberately **not** constraints. A compare-at price above the price is refused by the form but not by the database, because repricing an item upwards can strand an old offer and `hasComparePrice()` is what hides one. And "no third level of category" needs a subquery, which a CHECK cannot hold, so it stays in `MenuCategoryObserver`.

`tenants_type_is_known` and `charges_calculation_is_known` are built from `TenantType::cases()` and `ChargeCalculation::cases()`, so adding a case is an enum case and a `migrate:fresh` — though a new calculation also needs its line in `charges_value_matches_calculation`. A constraint may read an enum here only because each table's one migration is edited in place rather than replayed against an older enum.

## Walk rows in chunks
Code that rewrites existing rows — a seeder, a command — uses `chunkById` and selects only the columns it needs, so a tenant with a long menu costs the same memory as one with a short one. Never `get()` a whole table into an array to loop over it.

## Timestamps are Asia/Kolkata, set from the environment
`APP_TIMEZONE` drives `config/app.php` and `DB_TIMEZONE` is handed to the pgsql connection, so `now()` in PHP and `now()` in SQL agree. Neither is hardcoded anywhere, and `phpunit.xml` pins the same zone so tests behave as production does.
