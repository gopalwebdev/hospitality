---
paths:
  - app/Models/User.php
  - 'app/Models/**'
  - app/Models/MenuItem.php
  - app/Models/HomeTile.php
---

# Models

## Product team ownership is the is_super_admin column, not a role
`users.is_super_admin` is the single source of truth for the product team. `User::isSuperAdmin()` reads it, `canAccessPanel()` gates the platform panel on it, and `AppServiceProvider::configureAuthorization()` uses `Gate::before` to grant a super admin every permission without holding any role.

There is deliberately no `Role::SuperAdmin`. Spatie roles describe what someone does inside one tenant (Admin, Manager, Staff, Customer); product team ownership is global and orthogonal. Do not reintroduce a super-admin role — two sources of truth for this will drift.

The model declares `protected $attributes = ['is_super_admin' => false]` because the database default only lands on insert; without it an unsaved User throws MissingAttributeException under `Model::shouldBeStrict()`.

## The users resource puts a tenancy global scope on User
UserResource lives in the tenant panel, so Filament registers a tenancy global scope on User (and attaches anyone created during a tenant request to that tenant). Any query that must see accounts platform-wide has to say so: ->withoutGlobalScope(Filament::getTenancyScopeName()), as AddUserToTenant does when finding an existing account by address. Sign-in is unaffected because Filament resolves the tenant from the authenticated user, so a visitor at the login page has none. The scope only exists once the panel has booted, which HTTP requests do via middleware and the enterTenantPanel() test helper does with Filament::bootCurrentPanel() — a test that only calls setCurrentPanel() proves nothing about tenant isolation.

## Guards on Role and Permission go in booting(), never booted()
Spatie's HasPermissions trait registers a `deleting` listener that detaches a Role's users and permissions, and Eloquent boots traits between `booting()` and `booted()`. A guard registered in `booted()` is therefore asked its question *after* the links it inspects have already been cut, so `$role->users()->exists()` is always false there and the guard silently never fires.

Role::booting() and Permission::booting() hold the rename/delete guards for this reason. If you add another model event that inspects a Spatie relationship, register it in `booting()` too, and cover it with a test that calls `$model->delete()` directly — asserting `isInUse()` alone passes even when the guard is dead.

## tenant_id is where an account belongs; is_super_admin is what it may do
`users.tenant_id` names the tenant an account belongs to and is what the product team panel lists it under — null renders as "Product team". It grants nothing. `is_super_admin` remains the only source of product team ownership, because an ordinary account that has not been put on a roster yet also has a null tenant, and deriving powers from that would hand the platform to every half-created user.

The tenant_user pivot still exists alongside it: tenant_id is the one tenant they belong to, the pivot is every tenant they staff. Write both together (CreateUserAccount, EditUser::syncRoster, UserFactory::ofTenant) — a tenant nobody is rostered at names a panel the account cannot open.

## Read withCount values for display, query fresh before destroying
`Role::undeletableReason()` and `Permission::isInUse()` prefer a `withCount()` value already on the model (via the `ReadsLoadedCounts` trait) and fall back to a query. A list page loads those counts once for the whole page, so asking the model again per row cost 57 extra queries on the roles table before this — the page is now flat at 6 queries whatever the row count, pinned by a test in RoleManagementTest.

The `deleting` hooks deliberately do **not** use those helpers: they call `users()->exists()` / `permissions()->exists()` / `roles()->exists()` directly. A count loaded when a page rendered is right for deciding what to show and wrong for deciding what to destroy.

## Every level of the menu carries tenant_id, enforced by composite foreign keys
The menu is `menus` → `menu_categories` (both levels of section) → `menu_items` → `menu_item_additions`, plus `menu_combos` and `menu_combo_items` hanging off a menu, and every one of them carries `tenant_id` directly as well as reaching it through its parent. The home screen is two — `home_rows` → `home_tiles` — and does the same, as does a tile for the menu it opens.

That duplication is deliberate and safe: each table has a unique `(id, tenant_id)`, and its child has a **composite** foreign key on `(parent_id, tenant_id)` referencing the pair. Filing a section under another tenant's menu, a dish under another tenant's section, or an addition on another tenant's dish is therefore a database error, not something a forgotten `where()` can let through. Every one of those is pinned by a test in `tests/Feature/Tenant/MenuManagementTest.php`.

## Both levels of section are one table, and that was a deliberate reversal
`menu_categories.parent_id` is nullable and self-referencing: no parent means a section of the menu, a parent means a subdivision of that section. A `menu_sub_categories` table was built first and replaced by this, and the reasons are worth keeping because the two-table shape looks tidier on paper.

What the merge bought:

- A dish names **one** category, at whichever level. The two-table shape gave `menu_items` a required `menu_category_id` beside a nullable `menu_sub_category_id`, plus a composite key referencing `(id, menu_category_id)` to stop the pair drifting. All of that is simply gone — there is no pair, so there is nothing to police.
- Moving a subdivision under a different section is one `parent_id` write, and its dishes are untouched because they name the subdivision rather than its parent. Under two tables the dishes carried both halves, so no order of two statements was legal at every step and an `ON UPDATE CASCADE` was needed to make the move possible at all.
- The sub-categories table in the panel became a plain `hasMany` instead of a `HasManyThrough`, which removed a `reorderTable()` override that existed only to dodge the join's ambiguous `id`.

What it still guarantees, and how: `(parent_id, menu_id)` is a composite self key referencing `(id, menu_id)`, so a subdivision can never sit under a section on another menu — and being `ON UPDATE CASCADE` is what carries a whole branch across when its section moves menus. `MoveCategoryToMenu` relies on that; do not "fix" it by rewriting the children by hand.

Two levels, no more. `MenuCategory::booted()` refuses a parent that is itself nested and refuses a row as its own parent, because no foreign key can say either.

Uniqueness is per level: the expression index is `(menu_id, COALESCE(parent_id, 0), (name ->> 'en'))`. The COALESCE is load-bearing — a unique index treats NULLs as distinct, so without it every top-level category would escape the constraint entirely.

Keep both columns in step when writing rows. The factories exist for exactly this — `MenuCategoryFactory::inMenu()` / `::under()`, `MenuItemFactory::inCategory()`, `MenuItemAdditionFactory::onItem()`, `MenuComboFactory::onMenu()`, `MenuComboItemFactory::pairing()`, `HomeTileFactory::inRow()` / `::openingMenu()` — and setting the halves independently trips the key. `MenuCategoryFactory::under()` sets `parent_id`, `menu_id` and `tenant_id` together for that reason.

`MenuCategory`, `MenuCombo`, `MenuComboItem`, `MenuItemAddition` and `HomeTile` all derive `tenant_id` from their parent in `booted()`, because Filament's tenancy stamps the model a *resource* is saving but not the rows a repeater or a relation manager writes alongside it. Categories joined that list when they stopped being a resource of their own and moved onto the menu's page.

A booted Filament panel stamps `tenant_id` on **every** model created during the request, so a factory that picks its own tenant will now trip these keys. Create fixtures before `enterTenantPanel()`, or name the parent explicitly.

Never resolve an item's currency or tax rate through `$item->tenant->settings`: that is a lazy load, which `Model::shouldBeStrict()` throws on in local and in the test suite and which is an N+1 down a list of dishes. `App\Models\Concerns\IsPricedOnAMenu` holds `currency()`, `taxRateBasisPoints()`, `formattedPrice()` and `formattedComparePrice()` once for both `MenuItem` and `MenuCombo`; every reader takes an optional override, and a list should pass one, because every row shares the tenant's answer.

## Nothing on the menu is hard-deleted to take it off
`menu_items.availability` and `menu_combos.availability` are `App\Enums\ItemAvailability` — Available, OutOfStock, TemporarilyUnavailable — not a boolean. The boolean could only say *whether* a dish was off, so a kitchen that had run out of prawns and one that had stopped serving biryani looked identical, and both read to a guest as though the dish had been withdrawn.

`isOrderable()` is the single place "showing" and "orderable" are distinguished, and `ItemAvailability::orderableValues()` is what queries filter on, so a fourth case cannot leave a query behind. The reason never reaches a guest: `MenuController` leaves an unorderable dish out of the payload entirely rather than sending it greyed, because a phone menu should not be scrolled past things nobody can have.

`menu_item_additions` deliberately keeps a plain boolean `is_available`. An addition that has run out is simply not offered, so there is nothing for the extra cases to say there.

## Guest-facing text is a translated JSON column, unique on English
Menu, MenuCategory, MenuItem, MenuItemAddition, HomeRow and HomeTile store their guest-facing text with spatie/laravel-translatable: the column is `jsonb` holding one key per App\Enums\Locale case, and the model declares `public array $translatable`. Use App\Models\Concerns\HasTranslatedNames, never Spatie's trait directly — it adds the one thing the package leaves open, which is that English (Locale::default()) is privileged.

English is required in the admin forms, is the fallback a guest gets when a translation is missing, and is what every unique index is built on. Those indexes are expression indexes created with a raw `DB::statement` (`... (menu_id, (name ->> 'en'))`) because Blueprint cannot express one.

Consequences: a plain `unique` on the column would compare whole JSON documents and let duplicates through; `where('name', $x)` never matches, use `where(Model::fallbackLocalePath(), $x)` or `'name->en'`; `pluck('name->en')` comes back keyed by the path, so read models and use `$model->name`; and `orderBy`/`searchable` in a Filament table must go through TranslatedFields::sort()/search(), which answer in the panel's language with English as the fallback — a resource's global search goes through `TranslatedFields::searchableAttributes()` for the same reason. Spatie's toArray() returns one language, so admin forms fill with `$record->fillTranslationsInto($data, ...)`.

## A tile's action and its destination are paired in the model and in the schema
home_tiles has three nullable destination columns — menu_id, document_path and url — and App\Enums\HomeTileAction::targetColumn() is the single place that says which one an action uses. HomeTile::booted() clears the ones the action does not use and throws when the required one is blank; HomeTileForm states the same rule as `visible()`/`required()` validation.

The database says it too: `home_tiles_destination_matches_action` is a CHECK constraint requiring exactly the action's column to be filled. Keep the model guard all the same — it clears the columns an action does not use before the save, which is what lets a tile change action at all, and it fails with a message rather than a constraint violation. Adding a third action means an enum case, a column, a case in targetColumn(), and a migration replacing that constraint.

## Timestamps are CarbonImmutable, and prices leave as integers
`AppServiceProvider` calls `Date::use(CarbonImmutable::class)`, so every `@property` for `created_at` / `updated_at` says `CarbonImmutable`. A docblock saying `Carbon` is wrong and will have someone reaching for `->addDay()` expecting it to mutate.

Money stays an integer all the way out of PHP. `MenuItem::formattedPrice()` exists for the Filament tables, which are server rendered; the guest app is sent `price_minor_units` and format it themselves — see `.ai/rules/js.md`. Do not add a `formattedX()` accessor for an Inertia payload.

## The tenant is a Tenant, and every key to it is tenant_id
The tenant boundary is `App\Models\Tenant` on the `tenants` table, whatever kind of business it is (`tenants.type`, `App\Enums\TenantType`). It was `Restaurant` on `restaurants` until the platform took on hotels: the foreign key columns became `tenant_id` first, and the tables (`tenants`, `tenant_settings`, `tenant_user`), the model, the tenant panel and the `restaurant.manage` permission followed in the `2026_09_11_0808*` migrations. Do not reintroduce "restaurant" for the boundary itself; it is still right for what a restaurant *is* — GST on restaurant service, say.

Every foreign key pointing at `tenants` is called `tenant_id` — users, menus, menu_categories, menu_items, menu_item_additions, menu_combos, menu_combo_items, home_rows, home_tiles, tenant_settings and the tenant_user pivot. That is now exactly what Laravel infers from the model, so relationships take no key argument: `belongsTo(Tenant::class)`, `hasMany(Menu::class)`, and `belongsToMany(User::class)`, whose conventional pivot is `tenant_user`. Filament's default ownership relationship is `tenant()` for the same reason, so a resource sets `$tenantOwnershipRelationshipName` only where it differs — `UserResource`'s `tenants`.

Generated names followed neither rename: foreign keys, uniques, primary keys and sequences may still say `restaurant_id` or `restaurants`, because Postgres carries a constraint through a column or table rename but not its name, and nothing queries by them. Every name a migration writes out as a literal *was* renamed — the expression indexes, and the CHECK constraints (`tenants_role_limits_not_negative`, `tenant_settings_tax_rate_in_range` and the rest) — because the next migration that replaces one will type the new name.
