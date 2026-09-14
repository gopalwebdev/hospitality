---
paths:
  - 'app/**'
---

# App

## Accounts have no password: sign-in is an emailed one-time code
There is no `password` column, no password_reset_tokens table, and no Fortify. The only way into either Filament panel is App\Filament\Auth\Login, a two-step page that issues a code and then checks it.

User::getAuthPassword() deliberately returns '' rather than being removed. Laravel's AuthenticateSession middleware (in both panel middleware stacks) reads it on every request and falls through when it is empty; without the override the model would throw under Model::shouldBeStrict().

Do not reintroduce a password field, a "forgot password" flow, or passkeys without saying so explicitly.

## Thin controllers, behaviour in invokable action classes
HTTP and Livewire/Filament classes stay thin: they validate, call one thing, and return a response. Every unit of behaviour is a single-purpose invokable class under app/Actions/<Area>/ (see app/Actions/Otp/), resolved with app(...) and called as $action($args).

Follow SOLID: one reason to change per class, depend on the abstraction, extend rather than branch on a type. Prefer a model query scope over repeating a where clause in a controller or page (see User::scopeWithEmail).

Laravel's own idiom wins over cleverness: named routes, Form Requests, Eloquent relationships, artisan make: for new files, and `vendor/bin/pint` before finishing.

## N+1 and duplicate queries throw in local and in the test suite
`AppServiceProvider::configureQueryGuards()` turns on `Model::shouldBeStrict()` — lazy loading, unselected attributes, silently discarded attributes — and `preventDuplicateQueries()`, in the `local` and `testing` environments and nowhere else. The suite is included on purpose: it is the one place every page is exercised on every change, and a guard that only ran in local would let an N+1 merge.

A duplicate is the same SQL with the same bindings twice inside one HTTP request, Livewire's own requests included, counted from `RouteMatched` to `RequestHandled`. Three things are deliberately not duplicates:

- anything outside a request — migrations, seeders, queued jobs, console commands, a test's own set-up;
- a read repeated after a **write** in the same request, which is a refresh (a table redrawn after an action, a relation reloaded after a sync) — any statement that is not a `select` clears the set;
- a repeat no application code asked for. `DuplicateQueryException::applicationOrigin()` walks the backtrace, passes over middleware frames that only hand the request to Laravel's pipeline, and does not throw when no application frame is left — Filament or Spatie doing their own work twice is nothing this codebase can fix.

The fixes, in order of preference: read the answer once and hand it down; eager load what a loop asks for and name the columns (an eager load of `menu:id,name` never collides with a table's own `select *`); and where Filament evaluates the same closure several times while building one page — select options, a `disabled()` beside a `helperText()`, a rule on every language's input — memoize it with `once()`. `configureRequestMemoization()` flushes `Once` on every `RouteMatched`, so `once()` means once per request rather than once per process; without it a test that makes several requests reads a stale option list.

`Tenant::resolvedSettings()` is the pattern for a per-request lookup on a model: loaded once and kept as the `settings` relation, never a lazy load. `currency()`, `taxRateBasisPoints()` and `isAcceptingOrders()` all read it, as do the guest menu's tax line and the Settings page.

## India is the only market for now
Defaults are Indian: CountryCallingCode has one case (+91) and mobile numbers validate as ten digits, and addresses take a pincode. This is a "for now", not a permanent assumption, so keep this shape multi-country: values that vary by country belong in an enum with a case per country rather than hardcoded in a form or a rule. Add the country to the enum rather than branching on it at the call site.

Currency and timezone are a harder line than that, by product decision, not just a default: `App\Enums\Currency` has exactly one case (`IndianRupee`) and `tenant_settings` carries no timezone column at all — the application timezone comes from `APP_TIMEZONE`/`config('app.timezone')` alone (`.ai/rules/config.md`), never a per-tenant choice. Re-adding either a currency picker or a per-tenant timezone needs a product decision first, not just an enum case — this project has explicitly decided against them "for now," which is a stronger statement than the multi-country shape above.

## A tenant caps its own owners and staff
`tenants.max_owners` and `tenants.max_staff` (default from `config('tenants.php')`, editable per tenant by an admin on TenantForm) bound how many accounts may hold the Owner or Staff role on that tenant's roster at once — one admin and five staff out of the box. `Tenant::roleLimit()` and `Tenant::roleHolderCount()` answer "how many, and how many allowed"; `App\Actions\Tenants\EnsureRoleFitsWithinLimit` is the single place every role grant is checked against it, called from `SetTenantUserRoles` (tenant panel) and `SetUserRoles` (product team panel) — never bypass either action to write a role directly.

Lowering a limit below the tenant's current roster is refused at the form field (`TenantForm::notBelowCurrentHolders()`), naming how many to remove first, rather than silently locking the extra accounts out of a role they still hold.

## Say "product team", not "platform staff"
The people who run the whole product are the **product team** — that is the vocabulary in class names, method names, comments and UI copy: `isProductTeamOnly()`, `productTeamOnlyValues()`, `belongsToProductTeam()`, `enterProductTeamPanel()`, and "Product team" wherever a null tenant is rendered.

"Platform" is still correct for the *software*, and is deliberately kept: "accounts are platform-wide", "every tenant on the platform", and the platform panel's brand name "Hospitality Platform". The distinction is people versus product — do not rename those back.

## Three surfaces: two Filament panels and the guest app
Settled architecture, one surface per audience:

1. **Product team** — Filament platform panel, root domain, `/dashboard` (entered at `/login`). Tenants, roles, permissions, accounts.
2. **Tenant** — Filament tenant panel, tenant subdomain, `/dashboard` (entered at `/login`), for a tenant's owners and staff alike. Menu, charges, settings, reports, receipt printing.
3. **Guest** — React + Inertia, phone-first, installable as a PWA; arrives by QR and lands on a home screen the tenant arranges out of rows of tiles (`home_rows` → `home_tiles`), walking from there into a menu, a PDF, or off to a link.

Both panels live under `/dashboard` and are told apart by host, and `/login` on either host is the way in — `.ai/rules/filament.md` explains why that depends on provider order. They were once named for roles, at paths and in folders to match; they are named for whose they are now, because a tenant's panel serves its staff as much as its owners.

A staff app (React, phone-first) existed and was removed on the project owner's instruction. When staff get a surface again it is React rather than a third panel, for the reason it was before: they are on phones, and `.ai/rules/filament.md` reserves panels for laptop-and-larger. The guest app does not work offline — there is no offline requirement, and Inertia needs the server for every page.

Keeping the guest app the only Inertia surface on a subdomain is what keeps its bundle free of Filament assets. Do not import Filament into it, and do not add a Filament panel for a phone audience.

## The menu is four levels, one of which nests once, and a guest lands on tiles
`menus` → `menu_categories` → `menu_items` → `menu_item_additions`, where `menu_categories` holds **both** levels of section: a row with no `parent_id` is a section of the menu, and one with a parent is a subdivision of that section. A tenant that serves one card all day simply keeps one menu; one that serves a different card at lunch has two.

The menu is generic, on the project owner's instruction: one card carries things to order and **service requests** side by side — a bottle of water beside an extra pillow — and the wording rule in `.ai/rules/general.md` keeps the code, the copy and the rules that way. An item is either (`menu_items.is_service_request`), and only a thing to order carries a diet mark; see `.ai/rules/models.md`.

Subdividing is optional, and the depth is capped at two. An item names exactly one category whichever level it sits on, so there is no (category, sub-category) pair to keep consistent — that is the whole reason the two levels share a table. A separate `menu_sub_categories` table was built first and replaced; see `.ai/rules/models.md` for what the merge bought.

There is deliberately no third level. `MenuCategoryObserver` refuses a parent that is itself nested, because no constraint can express that and arbitrary nesting brings cycle checks and an ordering story nobody has asked for.

Alongside the sections, a menu carries `menu_combos` — bundles sold at one price, each listing existing items in `menu_combo_items` with a quantity. A combo hangs off the **menu** rather than a category, because it is something the menu leads with rather than something in a section, and its price is its own: a combo exists precisely because it costs less than the sum of its parts, so nothing derives one from the other.

Add-ons (`menu_item_additions` — the table keeps its name, and everything a person reads says "Add-ons") are a flat list per item: a name plus a price delta, and zero is a real price — "no onions" costs nothing and is still worth listing, and so does "memory foam" on a pillow. Grouped choices with rules — "pick exactly one size", "up to three toppings" — and per-item variants are a real thing a menu eventually needs and belong in a layer **above** `menu_item_additions`, not inside it. Variants were considered and deliberately left out for now; sizes are modelled as add-ons until then.

What a guest sees first is `home_rows`, each holding its own `home_tiles`. The **row** owns the layout — `App\Enums\HomeRowLayout` is `Banner` (full-width rectangles), `Carousel` (a swipeable rail of pictures) or `Links` (small circles for Instagram, WhatsApp, a phone number) — and every tile in it is drawn that way, which is why there is no shape column on a tile. The **tile** owns its destination: a menu, an uploaded PDF, or an external link. That is why `/` is the home screen and a menu lives at `/menus/{menu}`.

"Row" and not "section": the panel already calls `menu_categories` sections, and one word for two different things is how a reader ends up on the wrong page.

A menu carries two **rails** as well as its categories: the items the tenant leads with (`menu_items.is_featured`, ordered by `featured_position`) and its combos. Where they sit among the categories is the tenant's own decision — each is a `menu_blocks` row whose `position` shares the number space of `menu_categories.position`, and `Menu::readingOrder()` merges the two. A rail has no row until it is placed, and one without a row reads at the top, featured first, so a menu nobody has arranged opens with its featured items and then its combos. `menu_blocks` is also where a later kind of menu content, a banner say, goes (`.ai/rules/menus.md`). A featured item still appears under its own category further down, so a guest scrolling finds it where they expect it.

Every list a guest reads is ordered by `position` within its own parent — the blocks of a menu, subdivisions within a category, items within a category, add-ons within an item — and each is dragged into that order in the panel from the menu's arrangement — the blocks and categories on its outline, and what is inside each in the table its row opens (`.ai/rules/menus.md`). Nothing is ordered alphabetically, and nothing is ordered across parents.

Featuring belongs to **one menu**, so an item that leaves a menu stops being featured — `MenuItemObserver` clears the flag when an item's category crosses menus, and `MoveCategoryToMenu` clears it for a whole branch, rather than letting an item appear at the top of a menu nobody chose it for.

Prices carry an optional `compare_at_price_minor_units` — the higher "was" price shown struck through — which is null on almost every row, because null is how an item says it is not on offer and a zero would be a price of nothing. It is refused unless it is strictly above what is charged. Named for what it is rather than "strike price", which in every other software context means the exercise price of an option. A price of zero is complimentary.

`menu_items`, `menu_item_additions` and `menu_combos` each carry a nullable `tax_rate_basis_points` that falls back to `tenant_settings.tax_rate_basis_points`, so a tenant sets its rate once and only genuinely different lines — a sealed bottle taxed as goods rather than as a served drink, a laundry pickup taxed as a service — override it. The Settings page holds only the global rate and `prices_include_tax`.

## Charges are their own module, on every menu or only some
What is added to a bill beyond the price — a service charge, a packing charge, a room-service fee — is `charges`, managed on its own Charges page in the tenant panel rather than as settings: a tenant may levy any number, each is a share of the bill or a fixed amount, and each applies to every menu or only the menus ticked (a room-service fee on in-room dining and not on housekeeping requests). Two fixed switches on `tenant_settings` existed and were replaced, because they could say a service charge and a packing charge and nothing else, and could not say which menus either belonged on.

The guest menu lists only the charges its own menu carries. Nothing adds them up yet — there is no cart — but `Charge::amountOn()` is the arithmetic a cart will use. See `.ai/rules/models.md` for the pairing rules.

## Two languages a tenant writes in; the application itself is English
`App\Enums\Locale` has one case per language a tenant may write its menu in — English and Tamil for now — and is the single source of truth: the cookie middleware validates against it, the toggle is built from it, and every translated column stores one key per case. English is the default, the fallback, and the only language an admin form requires.

**The application's own words are not part of that.** `lang/en` is the only language directory; a `lang/ta` mirroring every string existed and was deleted on the project owner's instruction — the codebase is written in English and translation lives at the database level only. So switching language changes the menu a guest reads and leaves the words around it alone. Do not reintroduce a second language directory; adding a language is a case and nothing else. See `.ai/rules/lang.md`.

Like the India assumption above, the pair of languages is a "for now". The one thing that does not scale for free is uniqueness, which every form checks on the English name — see `.ai/rules/models.md`.

All three surfaces switch language: the guest app through its toggle, and both Filament panels through a switcher in the top bar. What that switch reaches is the tenant's own words — menu, category, item, add-on, charge and tile names. Roles and permissions stay English too, and for a second reason: code refers to those names.
