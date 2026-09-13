---
paths:
  - 'database/migrations/**'
---

# Migrations

## Strict schema, enums for fixed value sets, light normalization
Columns declare their real type and nullability — no catch-all strings, no nullable-by-default. Every relationship is a real foreign key with an explicit onDelete, and anything that must not repeat gets a unique or composite-unique index.

Any fixed set of values is a PHP backed enum in app/Enums/ (TitleCase cases) cast on the model, not a loose string column. The enum owns its own behaviour — see Role::permissions() and FilamentPanel::path().

Normalize to roughly 3NF and stop: pull repeating groups into their own table with a pivot (tenant_user), but do not shred simple value objects into tables for the sake of it.

## Money is stored as an integer in the minor unit
Never a float or a decimal string: every monetary column is an integer holding the smallest unit of its currency, so ₹249.50 is stored as 24950. Arithmetic stays exact and no rounding creeps in between the database and a payment provider. Convert to and from a display value at the edge, and name the column so the unit is unmistakable. The currency itself is on tenant_settings.currency, cast to App\Enums\Currency.

## A phone number is two columns: calling code and national number
Never one free-text string. The calling code goes in its own column cast to App\Enums\CountryCallingCode (backing values carry the plus, '+91', which keeps them strings when used as array keys), and the national number goes in a column of its own holding digits only — ten of them for India. Size number columns to CountryCallingCode::longestMobileNumberLength(), so a country with longer numbers needs a migration as well as an enum case. Tenant::dialablePhone() puts the two halves back together for display; nothing else should concatenate them by hand. See tenants.phone_country_code/phone for the shape.

## A translated column is jsonb, and its unique index is an expression
Any text a guest reads is a `jsonb` column holding one key per App\Enums\Locale case, not a string — see `.ai/rules/models.md`. They began as `json` and were converted by the `add_postgres_types_and_checks_to_*` migrations: `jsonb` is stored parsed, has the equality and ordering operators plain `json` lacks, and Postgres rebuilds an expression index over the column as part of the type change, so nothing has to be dropped around it. A new translated column is `$table->jsonb(...)`.

A plain `$table->unique(['menu_id', 'name'])` is still useless: JSON documents only compare equal when every language in them does, so two menus both called "Dinner" slip through the moment their Tamil halves differ. Put the constraint on the fallback language, with a raw statement, because Blueprint cannot express an expression index:

```php
DB::statement("CREATE UNIQUE INDEX menus_tenant_id_name_en_unique ON menus (tenant_id, (name ->> 'en'))");
```

Convert values to JSON text **while the column is still text** — Postgres will not cast `Starters` to json — and create the index after the type change. `translate_menu_names_and_descriptions` does both in that order and its `down()` mirrors them.

Several older migrations carried a second copy of these index statements to repair what a SQLite table rebuild strips. SQLite is gone (`.ai/rules/config.md`), and so are those repairs; do not reintroduce them.

## Rules the database can state, it states
Postgres is the only engine, so a rule a CHECK constraint can express is written as one as well as in the model and the form — raw `ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)`, dropped by name in `down()`. What exists: money, positions and role limits are never negative (Postgres has no unsigned integers, so `unsignedInteger` alone promised nothing); rates are 0–10000 basis points; a combo holds a dish at least once; a menu's service window is both times or neither; a category is not its own parent; and a tile's action and its destination match — exactly the column its action uses is filled.

Two things are deliberately **not** constraints. A compare-at price above the price is refused by the form but not by the database, because repricing a dish upwards can strand an old offer and `hasComparePrice()` is what hides one. And "no third level of category" needs a subquery, which a CHECK cannot hold, so it stays in `MenuCategory::booted()`.

A constraint's values are written out, not read from an enum: a migration has to mean the same thing when it is run again after the enum has grown. A new tile action is a new migration replacing `home_tiles_destination_matches_action`. Before adding a constraint to a table with rows, check the existing data satisfies it — a failed validation aborts the deploy.

## One migration per table, and walk rows in chunks
A change that touches two tables is two migrations, each named for the table it touches — `translate_menu_category_names` and `translate_menu_item_names_and_descriptions` are one change split that way. It keeps a rollback surgical and a name honest about what it does.

A migration that rewrites existing rows uses `chunkById` and selects only the columns it needs, so a tenant with a long menu costs the same memory as one with a short one. Never `get()` a whole table into an array to loop over it.

## Timestamps are Asia/Kolkata, set from the environment
`APP_TIMEZONE` drives `config/app.php` and `DB_TIMEZONE` is handed to the pgsql connection, so `now()` in PHP and `now()` in SQL agree. Neither is hardcoded anywhere, and `phpunit.xml` pins the same zone so tests behave as production does.
