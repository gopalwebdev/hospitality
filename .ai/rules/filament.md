---
paths:
  - 'app/Filament/**'
---

# Filament

## Each panel owns its own Filament namespace; both live under /dashboard, entered at /login
Two panels, named for whose they are rather than for a role:
- **platform** — the product team; root domain; classes under app/Filament/Platform/, `PlatformPanelProvider`
- **tenant** — one tenant's owners **and** staff; its subdomain; classes under app/Filament/Tenant/, `TenantPanelProvider`

They were first named for roles, with paths and folders to match, and were renamed because a tenant's panel is used by its staff as much as its owners — a role in a URL, a folder or a route name was wrong for half its users. Do not name a panel, a path or a folder after a role again.

The tenant panel was later renamed from a name that fitted one kind of business to `tenant` (`app/Filament/Tenant/`, `TenantPanelProvider`, `filament.tenant.*`), for the same reason: a name that fits one kind of customer is wrong for the rest. This is one common product, so its copy says "tenant" too — see `.ai/rules/lang.md`.

URLs, on either host: `/login` is the way in (`platform.login` on the root domain, `tenant.login` on a subdomain, both `PanelSignInController`), which sends someone signed out to the panel's own sign-in page at `/dashboard/login` and someone signed in straight to `/dashboard`. Every panel page lives under `/dashboard`. Filament keeps a panel's sign-in page under the panel's path, so `/login` is a redirect rather than the page itself: rendering the page at `/login` would need an empty panel path and a hand-prefixed slug on every page, and Filament's own `/` redirect would collide with the guest app's home. The unprefixed paths belong to the guest app (`/`, `/menus/{menu}`) and the welcome page (`/`).

The two panels share `/dashboard` and are told apart by host, which holds for one non-obvious reason: the tenant panel's sign-in route — and its logout, and its tenant redirect — carries **no domain**, because nobody has a tenant before signing in, so it answers on the root domain too. `PlatformPanelProvider` is therefore registered **before** `TenantPanelProvider` in `bootstrap/providers.php`, so the platform panel's root-domain routes are matched first; swap the order and the root domain's `/dashboard/login` becomes a tenant-less tenant sign-in. `PanelRoutingTest` pins it. A link to a tenant's panel is `Tenant::signInUrl()`, never `route('filament.tenant.auth.login')`, which has no host of its own.

App\Enums\FilamentPanel is the single source of truth for the Filament panel id (`platform`, `tenant` — route names are built from it), for the path, and for an installed panel's colour (`themeColor()`); the providers, the routes and User::canAccessPanel() all read it. Never hardcode a panel id or a panel path anywhere else.

Never put a page, resource or widget where both panels discover it. Shared behaviour goes in an abstract base under app/Filament/Auth/ (see OtpLogin) and each panel registers its own thin subclass.

## Built-in roles and permissions are guarded on the resource, not the policy
A role or permission whose name has an App\Enums case is built-in, because code refers to it by that name. What is withheld is narrow and deliberate:

- a built-in **role**: its permissions are fully editable, its name is not, and it may never be deleted
- a built-in **permission**: read-only, since its name is what `can()` checks
- any permission a role holds, or any role a user holds, may not be deleted until that link is undone

Those guards live in RoleResource/PermissionResource::canDelete() (and `disabled()` on the name field), NOT in the policy, because AppServiceProvider's Gate::before returns true for an admin before any policy method runs — a policy check would be skipped for exactly the people who can reach these pages. RoleObserver and PermissionObserver also throw on updating/deleting as a backstop, which is why neither resource offers a bulk delete.

Both panels run `strictAuthorization()`, so a resource whose policy lacks the method being asked about is refused rather than waved through. tests/Feature/PanelAuthorizationTest.php walks every registered resource and page in both panels and asserts an account holding nothing is refused, so a page added later cannot ship open.

## Panels are for laptops and larger screens, not phones
Both panels are back-office tools — the product team's, and a tenant owner's — used sitting down at a laptop or a bigger display. "Staff" in this codebase means floor staff on phones; they have no surface right now, and would not be given a panel. Design for that width and do not spend effort making a panel page work on a phone: no phone-first layouts, and no hiding columns below a breakpoint with visibleFrom()/hiddenFrom(), which only costs information when a laptop window is dragged narrow. A table may show every column it needs, and a form may assume the room to use columns().

This is enforced, not just intended: both panels render `resources/views/filament/desktop-only.blade.php` through the `BODY_START` render hook, which covers the panel with a "open this on a laptop" message below 1024px. It is CSS-only and inline, so it is correct on first paint and needs none of the utilities a panel does not ship. It is a door, not a second layout — building a phone layout for a panel is exactly what this rule rules out.

The guest app is the opposite — see .ai/rules/js.md, which is phone-only. A staff surface, when one comes back, is not a panel either: staff are on phones, and this rule is the reason.

Panels are served Filament's own compiled CSS, which carries its fi- classes and no general Tailwind utilities, so any styling of your own needs inline styles or a panel theme rather than utility classes.

## Never bind roles or permissions with Filament's ->relationship()
A `Select::make('permissions')->multiple()->relationship(...)` writes the pivot table directly, which bypasses Spatie's `syncRoles()` / `syncPermissions()` and so never calls `forgetCachedPermissions()`. The registrar then answers every `can()` check for the rest of the request from the set that was there before the save.

Use plain `->options()` instead, and hand the ids to an action on the page: SetRolePermissions (roles) or SetUserRoles (users). Both go through Spatie, which flushes the cache. Edit pages fill the field back in `mutateFormDataBeforeFill()`.

This is about **Spatie roles and permissions specifically**, because of that cache — it is not a ban on `->relationship()`. An ordinary relationship has no cache behind it: `MenuItemForm`'s add-on groups repeater (bound to the item's links) and `MenuAddOnGroupForm`'s options repeater use `->relationship()` on purpose so a record and its rows are written in one save, and `ChargeForm`'s menus select does too — with its options overridden to this tenant's menus and a rule refusing any other id, because `charge_menu` has no tenant to check.

**A relationship repeater fills from a relation that is already loaded.** Filament reuses the record's loaded relation instead of querying it again, so a table that eager-loads that relation with a column list hands its edit form rows missing every other column. The add-on groups table loaded `options` with four columns for its preview, and editing a group filled every option's price as nothing and refused a blank quantity. Eager-load such a relation with all its columns, or preview through something else.

**A `->table()` repeater gives a row's schema one cell per top-level component, hidden fields included.** It does not know a `TranslatedFields::text()` field is really two inputs (English and Tamil); spread directly into a row's schema, they became two cells, and everything after them shifted one column right — the add-on group options table showed a price under "GST", a GST rate under "Default", and never drew "Available" at all. `TranslatedFields::textCell()` wraps the pair in a `Group` so they count as one component; `Group` adds no state-path segment of its own, so `inRepeaterRow`'s `'../../'` lookup still finds the switcher two levels up. Use `textCell()`, never a bare `text()`, for a translated name inside a `->table()` repeater's row schema.

A column that comes and goes with an answer needs both lists to read that answer: `->table(fn (Get $get) => ...)` and `->schema(fn (Get $get) => ...)`, leaving the column and its field out together. Hiding the field alone still draws its cell. The add-on group's Max qty column works this way, pinned by a test comparing header cells with row cells in the rendered HTML.

In a Livewire test, read the modal's HTML from the partial whose key *starts with* `action-modals`. It is `action-modals` when mounted and `action-modals.0` after an update, and `html()` holds no modal at all.

## Translated fields are one box and one switcher, not a box per language
Guest-facing text is stored one value per language (`.ai/rules/models.md`). Build the inputs with `App\Filament\Schemas\TranslatedFields::text()` / `::optionalText()` / `::textarea()`, and put `TranslatedFields::localeSwitcher()` once at the top of the form.

There is still an input per `App\Enums\Locale` case underneath — that is how every language reaches the save in one go — but only the switched-to one is on screen. A form with a name and a description was four boxes; it is two, and it no longer grows by a box per field per language.

This replaced two-inputs-side-by-side. That arrangement was defended here as "less machinery", and it was, but it doubled the height of every form and left half of each line to a language most tenants fill in later. Do not go back to it without saying so.

Four things the switcher costs, all handled inside `TranslatedFields` and none of them optional if you add a field type there:

- **The switcher itself is set with `formatStateUsing()`, not `default()`.** A default only applies to a form filled with *nothing*, so every edit form — and every modal handed data, which includes a create modal with one field prefilled — opened with neither language lit. That is not cosmetic: the switcher decides which box is on screen and which value the "required in English" rule reads. Formatting the state normalises whatever the form was opened with, `null` included, to **the language the panel is being worked in** — the top bar's choice, which `SetLocale` puts on every request, Livewire's included, because it is in the `web` group. A panel switched to Tamil opens every form on Tamil; one nobody has switched opens on English. It was English unconditionally for one change, and a Tamil panel had to move the switcher on every record.
- Hidden languages carry `->dehydratedWhenHidden()`. Without it, typing the Tamil and saving would blank the English.
- The "English is required" rule rides on **every** language's input, reading the English value out of the form state. Left only on the English input it would never fire, because that input is hidden at exactly the moment it needs to.
- The uniqueness rule does the same, for the same reason — though only the input for the language on screen runs its query, since every input asks the same question about the English value and the duplicate-query guard would otherwise stop the page. It is built on the English name, and it is the only thing that refuses a duplicate — the schema has no unique indexes — so a rule that skipped while Tamil was on screen would store one.

A translated field in each row of a repeater — an add-on group's options — passes `inRepeaterRow: true`. A relative `$get()` resolves inside the row, where there is no switcher, so without it every row showed the panel's language whatever the switcher said; the add-ons repeater before it did exactly that.

A field that is hidden but `dehydratedWhenHidden()` is **validated while hidden** too. Give its rules the same condition as its visibility, and save what the other answers mean rather than what the box holds. An add-on group's "Same option more than once" is hidden while its Maximum is 1, and saved as off then, whatever it was left at.

Table columns must still go through `TranslatedFields::sort()` / `::search()` — both answer in the panel's language, with English for anything untranslated — and every edit action still needs `->mutateRecordDataUsing(fn (array $data, Model $record) => XForm::fillTranslations($data, $record))` — Spatie hands back one language, and a form editing all of them needs the whole document.

One trap in the uniqueness rule, which cost an afternoon. It ignores the record being edited so a name does not clash with itself, and it used to take that record from the `$record` a schema injects. **A schema is handed whatever record surrounds it**: on a resource or a relation manager that is the row being edited, but an action modal on a page falls back to *that page's* record — a `Menu` — and `whereKeyNot($menu->getKey())` silently excluded the category that happened to share the id, letting a duplicate straight through. So `uniqueFallbackValue()` now applies the exclusion only when the record is of the same model as the query, and a form opened from a table of arrays is handed the row explicitly (`MenuCategoryForm::configure($schema, $menuId, $editing)`). The injected parameter is typed `mixed` for the same reason — a custom-data table's record is an `array`, and `?Model` would be a TypeError.

## The panel is worked in a language too, and its labels are methods
Both panels carry a language switcher in the top bar (`resources/views/filament/language-switcher.blade.php`, hung on `USER_MENU_BEFORE`) and both list `SetLocale` in their own middleware stack — a panel does not run the `web` group, so the middleware that reads the language cookie has to be named there as well.

The form posts to the host it was rendered on, because a cross-host post loses the session: the tenant panel uses `preferences.language.update` with the tenant's slug, and the product team's panel uses the root-domain `panel.language.update`. Both hit the same controller.

**Labels must be methods, not static properties.** A `protected static ?string $modelLabel = 'menu'` is evaluated when the class loads, before the request has been served, so it cannot read anything request-scoped. Use `getModelLabel()`, `getPluralModelLabel()` and `getNavigationGroup()` returning `__('panel....')`.

The panel's own labels are English and stay English: `lang/en/panel.php` is the only file, and a `lang/ta/panel.php` was deleted deliberately (`.ai/rules/lang.md`). What the switcher still changes is the **tenant's** words — a menu, item or charge name comes out of a translated column and follows the chosen language, so a Tamil-reading manager reads their own menu in Tamil with the panel's labels in English around it. **Roles, permissions, accounts and tenants** are English for a second reason: code refers to those names.

## Both panels navigate as a SPA, and prefetch on hover
`->spa(hasPrefetching: true)` on both providers, so moving between pages is a Livewire visit with a progress bar across the top rather than a browser load, and hovering a link fetches its page before the click. Links inside a panel carry `wire:navigate.hover`; a form post — the language switcher, for one — is unaffected, and so is anything pointing off the panel's host (Filament compares hosts, so the product team's link into a tenant's subdomain stays a real browser visit).

Deployment runs `php artisan optimize` and `php artisan filament:optimize` (`composer deploy`), which cache Filament's components and Blade Icons. Never run those locally: a component cache stops new resources and pages being discovered until it is cleared.

## Both panels are installable, and neither works offline
The project owner asked for the panels to be PWAs. Both providers hang `resources/views/filament/progressive-web-app.blade.php` on `PanelsRenderHook::HEAD_END`. Filament's base layout renders that hook on every panel page, sign-in included, and sets no manifest or theme colour of its own. The view links the panel's manifest and registers its worker, both served by `PanelProgressiveWebAppController`.

- **Under `/dashboard`, never the root.** A tenant's subdomain also serves the guest app, whose worker is scoped to `/`. A panel worker at the root would replace it. Scoped to `/dashboard`, it takes only the panel's pages, and takes them off the guest worker, which would otherwise keep copies of them. The script is at `/dashboard/service-worker.js`, whose default scope (`/dashboard/`) misses `/dashboard` itself, so it is served with `Service-Worker-Allowed: /dashboard`.
- **The worker does nothing.** It has no fetch handler and no cache: a panel page is a signed-in Livewire page, and a stale copy is worse than the browser's offline page. Installing buys a window of its own, not offline use.
- **The tenant panel's sign-in page is not installable.** It has no tenant, so there is no app to name. A tenant's panel installs as "{name} Dashboard", apart from its guest app, which installs under the bare name.
- **A switched-off tenant's panel is still installable.** The panel does not check `is_active`, so neither does its manifest; only the guest app's does. An unknown subdomain is a 404.

Tests: `tests/Feature/PanelProgressiveWebAppTest.php`.

## No theming in the panel
A tenant chooses no colours and no light/dark default. `App\Enums\Appearance` has two cases and lives on the phone. Do not add a theme section back without asking.

The Settings page has three sections — Contact, Trading and Tax — and no others. Tax holds the GSTIN, the default GST rate every price falls back to, and whether menu prices already include it. `Settings::readableRates()` / `::storableRates()` are the only place the rate is converted, so the rounding is done once.

Charges are **not** a section of it any more: they are the Charges page (`App\Filament\Tenant\Resources\Charges\ChargeResource`), beside Settings in the navigation and behind the same `settings.manage` permission, because a tenant may levy any number and each is limited to some menus. Every rate and amount there is typed the way a person says it — "10" percent, "20" rupees — and stored the way the rest of the application stores its kind: rates in basis points, money in minor units. `ChargeForm::storeValue()` / `::fillValue()` are the only place that conversion happens, through `PricingFields`. A charge a tenant does not want is switched off rather than set to zero. (For a service charge that distinction is the point: the CCPA's 2022 guidelines make it voluntary.)

## Form fields carry no helper text
A label and a sensible input say what a field is. A paragraph under every one of them turned the item form into a page and a half of prose to fill in one line of prices, and an admin who fills that form daily reads none of it after the first time.

So: no `helperText()` on a form field, and no `->description()` on a form section. Where a field genuinely needs explaining, the fix is usually a better label, a `placeholder` that shows what happens if it is left empty (see the tax rate, which shows the tenant's own rate), or a `suffix`/`prefix` that names the unit.

Three things are deliberately **not** covered by this and stay: empty-state headings and descriptions, which is where a tenant with nothing set up needs telling what the page is for; the `modalDescription()` on a destructive action, because what a delete takes with it cannot be inferred from a button; and validation messages, which explain a refusal that has already happened.

A conditional table `description()` — one shown only while an action is unavailable — was tried on the items page to explain how to enable dragging, and taken out again. The rule is not "no permanent prose", it is **no prose**: a control that only appears once a filter is set is discoverable by using the filter, and a sentence saying so is a sentence to read every time you visit the page and do not need it.

## Forms stay compact, and icon buttons are named on hover
A form that fits the screen beats a column of full-width sections. Put related fields in a few `->compact()` sections and lay them side by side in a `Grid` with `->gridContainer()` and container breakpoints (`'@3xl' => 3`), so the same form sits side by side on a page and stacks in a modal — see `MenuForm`. The project owner found the menu's Edit tab, then four stacked sections, took far too much space.

Every icon-only action carries a `tooltip()` naming it; `iconButton()` alone leaves a pencil and a bin to be guessed at. On the menu page a row's actions are separate icon buttons, each with its tooltip and a colour for what it does. An `ActionGroup::buttonGroup()` strip was tried there first, and it drew misaligned icons and a solid red delete button (`.ai/rules/menus.md`).

A time of day is `App\Filament\Forms\Components\ClockTimePicker`: hour, minute and AM/PM columns in Filament's own dropdown, its state `HH:MM`.
- **Why not the alternatives:** Filament's `TimePicker` drew three small number boxes, and the browser's own picker looked different in every browser. The project owner asked for a clock picker.
- **Rule:** use it for any new time field.
- **Styling:** inline, from Filament's colour variables.

## Several choices are a multi-select dropdown
The project owner's standing instruction: a field that takes several values is `Select::make()->multiple()`, never a `CheckboxList`. Today that is:
- **A charge's menus.**
- **An account's roles,** in both panels.
- **A role's permissions:** one select per category, with a **Select all** hint action standing in for the checkbox list's bulk toggle.

The state is the same array as before, so the actions that save it did not change.

## No number is negative, and none carries arrows
Every numeric field declares `minValue()` of 0 or more. Filament renders it as the input's `min`, and the server refuses on it.

Both panels also hang `resources/views/filament/number-inputs.blade.php` on `HEAD_END`:
- **Arrows:** inline CSS hides the browser's spin buttons.
- **Typing and pasting:** one listener on the page refuses `-`, `+` and `e`, typed or pasted.
- **Wheel:** it stops the mouse wheel turning a focused number.

The project owner asked for both. A new numeric field needs only its `minValue()`.
