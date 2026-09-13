---
paths:
  - app/Models/User.php
  - 'app/Models/**'
  - app/Models/MenuItem.php
  - app/Models/HomeTile.php
---

# Models

## Models stay compact, and model events live in observers
Standing instruction from the project owner: a model holds relationships, scopes, casts and small accessors, with short docblocks — no essays. It never registers events with `booted()` or `static::saving(...)` closures. Behaviour that has to run when a row is written goes in an observer under `app/Observers/`, created only when a model actually needs one, and attached with `#[ObservedBy([...])]`. Today that is User, MenuCategory, MenuItem, MenuItemAddition, MenuCombo, MenuComboItem and HomeTile.

Role and Permission are the exception to `#[ObservedBy]`, not to observers. Laravel attaches an `ObservedBy` observer only once the model has finished booting, which is after Spatie's HasPermissions trait has registered the `deleting` listener that detaches a role's users and permissions — so a guard attached that way would ask `$role->users()->exists()` after the answer had been destroyed, and never fire. `Role::booting()` and `Permission::booting()` wire `RoleObserver` and `PermissionObserver` methods as `Class@method` listeners instead, which registers them first. `static::observe()` cannot be called there: it constructs the model, and constructing a model while it boots throws. Cover any such guard with a test that calls `$model->delete()` directly — asserting `isInUse()` alone passes even when the guard is dead.

## Product team ownership is the is_super_admin column, not a role
`users.is_super_admin` is the single source of truth for the product team. `User::isSuperAdmin()` reads it, `canAccessPanel()` gates the platform panel on it, and `AppServiceProvider::configureAuthorization()` uses `Gate::before` to grant a super admin every permission without holding any role.

There is deliberately no `Role::SuperAdmin`. Spatie roles describe what someone does inside one tenant; product team ownership is global and orthogonal. Do not reintroduce a super-admin role — two sources of truth for this will drift.

The model declares `protected $attributes = ['is_super_admin' => false]` because the database default only lands on insert; without it an unsaved User throws MissingAttributeException under `Model::shouldBeStrict()`.

## The users resource puts a tenancy global scope on User
UserResource lives in the tenant panel, so Filament registers a tenancy global scope on User (and attaches anyone created during a tenant request to that tenant). Any query that must see accounts platform-wide has to say so: ->withoutGlobalScope(Filament::getTenancyScopeName()), as AddUserToTenant does when finding an existing account by address. Sign-in is unaffected because Filament resolves the tenant from the authenticated user, so a visitor at the login page has none. The scope only exists once the panel has booted, which HTTP requests do via middleware and the enterTenantPanel() test helper does with Filament::bootCurrentPanel() — a test that only calls setCurrentPanel() proves nothing about tenant isolation.

## tenant_id is where an account belongs; is_super_admin is what it may do
`users.tenant_id` names the tenant an account belongs to and is what the product team panel lists it under — null renders as "Product team". It grants nothing. `is_super_admin` remains the only source of product team ownership, because an ordinary account that has not been put on a roster yet also has a null tenant, and deriving powers from that would hand the platform to every half-created user. `UserObserver` refuses an account that has both.

The tenant_user pivot still exists alongside it: tenant_id is the one tenant they belong to, the pivot is every tenant they staff. Write both together (CreateUserAccount, EditUser::syncRoster, UserFactory::ofTenant) — a tenant nobody is rostered at names a panel the account cannot open.

## Read withCount values for display, query fresh before destroying
`Role::undeletableReason()` and `Permission::isInUse()` prefer a `withCount()` value already on the model (via the `ReadsLoadedCounts` trait) and fall back to a query. A list page loads those counts once for the whole page, so asking the model again per row cost 57 extra queries on the roles table before this — the page is now flat at 6 queries whatever the row count, pinned by a test in RoleManagementTest.

The observers' `deleting` guards deliberately do **not** use those helpers: they call `users()->exists()` / `permissions()->exists()` / `roles()->exists()` directly. A count loaded when a page rendered is right for deciding what to show and wrong for deciding what to destroy.

## Every level of the menu carries tenant_id, kept in step by the observers
The menu is `menus` → `menu_categories` (both levels of section) → `menu_items` → `menu_item_additions`, plus `menu_combos` and `menu_combo_items` hanging off a menu, and every one of them carries `tenant_id` directly as well as reaching it through its parent. The home screen is two — `home_rows` → `home_tiles` — and does the same, as does a tile for the menu it opens.

The schema has no indexes (`.ai/rules/migrations.md`), so there are no composite foreign keys to hold the two halves together. `App\Actions\Tenants\InheritParentTenant` does instead, from the `saving` observer of every child row: a row with no tenant takes its parent's, and a row whose parent belongs to another tenant throws a LogicException — a category on another tenant's menu, a dish in another tenant's category, an addition on another tenant's dish, a combo line naming another tenant's dish, a tile in another tenant's row or opening another tenant's menu. It returns early for a saved row whose parent key and tenant are both unchanged, so renumbering a list costs no query and reads no column it was not given. Pinned in `MenuManagementTest`, `MenuCategoryTreeTest`, `MenuComboTest` and `HomeRowManagementTest`.

It runs on `saving` because Laravel fires that before `creating`, the event in which Filament's tenancy stamps `tenant_id` — and Filament does that only for a *resource* model (Menu, MenuItem, HomeRow), overwriting whatever was set. It never stamps the rows a relation manager or a repeater writes (categories, combos, combo lines, additions, tiles), which is why those derive theirs. A raw `DB::table()` insert goes around all of it.

Create fixtures before `enterTenantPanel()`, or name the parent explicitly: once the panel has booted, a resource model created during the test is stamped with the panel's tenant whatever its factory chose.

## Both levels of section are one table, and that was a deliberate reversal
`menu_categories.parent_id` is nullable and self-referencing: no parent means a section of the menu, a parent means a subdivision of that section. A `menu_sub_categories` table was built first and replaced by this, and the reasons are worth keeping because the two-table shape looks tidier on paper.

What the merge bought:

- A dish names **one** category, at whichever level. The two-table shape gave `menu_items` a required `menu_category_id` beside a nullable `menu_sub_category_id` that had to be kept consistent. There is no pair any more, so there is nothing to police.
- Moving a subdivision under a different section is one `parent_id` write, and its dishes are untouched because they name the subdivision rather than its parent.
- The sub-categories table in the panel became a plain `hasMany` instead of a `HasManyThrough`, which removed a `reorderTable()` override that existed only to dodge the join's ambiguous `id`.

Two levels, no more, and on one menu. `MenuCategoryObserver` refuses a parent that is itself nested, a row as its own parent, and a parent on another menu; `MoveCategoryToMenu` carries a category's sub-categories across with it, in the same transaction.

Uniqueness is per level and lives in the forms: a top-level name is unique within its menu, a sub-category's within its parent, both checked on the English name. Nothing in the database refuses a duplicate.

Keep the related columns in step when writing rows. The factories exist for exactly this — `MenuCategoryFactory::inMenu()` / `::under()`, `MenuItemFactory::inCategory()`, `MenuItemAdditionFactory::onItem()`, `MenuComboFactory::onMenu()`, `MenuComboItemFactory::pairing()`, `HomeTileFactory::inRow()` / `::openingMenu()` — and setting the halves independently is refused by the observers.

Never resolve an item's currency or tax rate through `$item->tenant->settings`: that is a lazy load, which `Model::shouldBeStrict()` throws on in local and in the test suite and which is an N+1 down a list of dishes. `App\Models\Concerns\IsPricedOnAMenu` holds `currency()`, `taxRateBasisPoints()`, `formattedPrice()` and `formattedComparePrice()` once for both `MenuItem` and `MenuCombo`; every reader takes an optional override, and a list should pass one, because every row shares the tenant's answer.

## Nothing on the menu is hard-deleted to take it off
`menu_items.availability` and `menu_combos.availability` are `App\Enums\ItemAvailability` — Available, OutOfStock, TemporarilyUnavailable — not a boolean. The boolean could only say *whether* a dish was off, so a kitchen that had run out of prawns and one that had stopped serving biryani looked identical, and both read to a guest as though the dish had been withdrawn.

`isOrderable()` is the single place "showing" and "orderable" are distinguished, and `ItemAvailability::orderableValues()` is what queries filter on, so a fourth case cannot leave a query behind. The reason never reaches a guest: `MenuController` leaves an unorderable dish out of the payload entirely rather than sending it greyed, because a phone menu should not be scrolled past things nobody can have.

`menu_item_additions` deliberately keeps a plain boolean `is_available`. An addition that has run out is simply not offered, so there is nothing for the extra cases to say there.

## Guest-facing text is a translated JSON column, unique on English
Menu, MenuCategory, MenuItem, MenuItemAddition, HomeRow and HomeTile store their guest-facing text with spatie/laravel-translatable: the column is `jsonb` holding one key per App\Enums\Locale case, and the model declares `public array $translatable`. Use App\Models\Concerns\HasTranslatedNames, never Spatie's trait directly — it adds the one thing the package leaves open, which is that English (Locale::default()) is privileged.

English is required in the admin forms, is the fallback a guest gets when a translation is missing, and is what every uniqueness rule checks.

Consequences: `where('name', $x)` never matches, use `where(Model::fallbackLocalePath(), $x)` or `'name->en'`; `pluck('name->en')` comes back keyed by the path, so read models and use `$model->name`; and `orderBy`/`searchable` in a Filament table must go through TranslatedFields::sort()/search(), which answer in the panel's language with English as the fallback — a resource's global search goes through `TranslatedFields::searchableAttributes()` for the same reason. Spatie's toArray() returns one language, so admin forms fill with `$record->fillTranslationsInto($data, ...)`.

## A tile's action and its destination are paired in the observer and in the schema
home_tiles has three nullable destination columns — menu_id, document_path and url — and App\Enums\HomeTileAction::targetColumn() is the single place that says which one an action uses. `HomeTileObserver` clears the ones the action does not use and throws when the required one is blank; HomeTileForm states the same rule as `visible()`/`required()` validation.

The database says it too: `home_tiles_destination_matches_action` is a CHECK constraint requiring exactly the action's column to be filled. Keep the observer guard all the same — it clears the columns an action does not use before the save, which is what lets a tile change action at all, and it fails with a message rather than a constraint violation. Adding a third action means an enum case, a column, a case in targetColumn(), and an edit to that constraint in `create_home_tiles_table`.

## Timestamps are CarbonImmutable, and prices leave as integers
`AppServiceProvider` calls `Date::use(CarbonImmutable::class)`, so every `@property` for `created_at` / `updated_at` says `CarbonImmutable`. A docblock saying `Carbon` is wrong and will have someone reaching for `->addDay()` expecting it to mutate.

Money stays an integer all the way out of PHP. `MenuItem::formattedPrice()` exists for the Filament tables, which are server rendered; the guest app is sent `price_minor_units` and format it themselves — see `.ai/rules/js.md`. Do not add a `formattedX()` accessor for an Inertia payload.

## The tenant is a Tenant, and every key to it is tenant_id
The tenant boundary is `App\Models\Tenant` on `tenants`, whatever `tenants.type` holds — this is one common product, and nothing outside `App\Enums\TenantType` names a kind of business. Settings are `tenant_settings`, the roster pivot is `tenant_user`, and the product team's permission is `tenant.manage`.

Every foreign key pointing at `tenants` is called `tenant_id`, which is exactly what Laravel infers from the model, so relationships take no key argument: `belongsTo(Tenant::class)`, `hasMany(Menu::class)`, and `belongsToMany(User::class)` over the conventional `tenant_user`. Filament's default ownership relationship is `tenant()` for the same reason, so a resource sets `$tenantOwnershipRelationshipName` only where it differs — `UserResource`'s `tenants`.
