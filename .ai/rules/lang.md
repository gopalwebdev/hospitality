---
paths:
  - 'lang/**'
---

# Lang

## This application's own words are English, and there is one directory
`lang/en` is the only language directory, and that is a decision rather than an omission. A `lang/ta` existed — a full mirror of `guest.php`, `staff.php` and `panel.php` — and the project owner deleted it: **the codebase is written in English, and translation lives at the database level only.**

What that splits, cleanly:

- **What a tenant wrote** — menu, category, dish, addition and tile names — is translated, in `jsonb` columns, one key per `App\Enums\Locale` case (`.ai/rules/models.md`). A guest switching language changes *this*.
- **What the application supplies** — "Sold out", "Rearrange", the panel's labels — is English, once. `HandleGuestAppRequests::translations()` therefore always reads the default locale's file and sends it as an Inertia once prop; it used to merge a translated file over the English one and no longer has anything to merge.

`App\Enums\Locale` still has both cases and still drives the toggles: it is the list of languages a *tenant may write in*, not the list of languages this application ships. Adding one is a case and nothing else — do not add a second directory here.

Filament's own chrome was always English regardless (the framework ships no `ta` locale), which is half of why a translated panel file was a poor trade: it half-translated a screen and left a second copy of every label to keep in step.

## One file per app, keys read in the browser
`lang/en/guest.php` holds the guest app's chrome — one file per app, so a staff app that comes back later gets its own rather than downloading the guest's. Nothing a tenant wrote belongs here: menu, section, dish, addition and tile names are translated database columns (see .ai/rules/models.md).

Each file is sent to the browser whole as the `translations` Inertia prop and read with `useTranslations()`'s dotted path. Laravel's `:name` placeholders are therefore filled in JavaScript, not PHP — keep them in the string rather than writing a second string with the number baked in. A key that is missing falls back to the path itself, so a typo reads as `menu.empy` rather than as nothing.

## panel.php is the tenant panel, and only its menu surfaces
`lang/en/panel.php` holds the labels of the Filament resources a tenant works in daily — menus, the arrangement, dishes, additions and home screen tiles — plus the navigation groups they sit under.

A string that names the business says **tenant** — `'This tenant already has a menu with that name.'` — whatever `tenants.type` holds. This is one common product, so no string varies by a tenant's type and none names a kind of business.

Roles, permissions, accounts and tenants are deliberately **not** in here. They are the product team's vocabulary and code refers to those names, so translating them would make a `hasRole('owner')` check read as though it might not be English.

Labels still reach Filament through `getModelLabel()` / `getNavigationGroup()` methods rather than static properties. That is now about the request rather than the language — see `.ai/rules/filament.md`.
