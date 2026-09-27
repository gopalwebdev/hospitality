---
paths:
  - 'app/Filament/Platform/Resources/Tenants/**'
---

# Resources Tenants

## Onboarding a tenant leads into creating its owner
TenantForm reads top to bottom as one sequence — Identity, Limits, Where it trades, How the platform reaches them — in a single column of full-width sections. A two-column grid of sections put the address beside the name, which read as two unrelated starting points.

Identity includes the tenant's **type** (`App\Enums\TenantType`). It has no default, so onboarding has to choose, and it stays editable afterwards on the project owner's decision — nothing depends on it yet, and no copy varies by it. The table shows it as a badge and filters on it.

CreateTenant does not return to the list. A tenant with nobody on its roster cannot be opened by anyone, so it redirects to `UserResource::getUrl('create', ['tenant_id' => ..., 'role' => 'owner'])` and CreateUser's `fillForm()` reads those two query parameters back. The account is deliberately made on the Users page rather than inline here, because that page holds the one-time code confirmation that authorises opening an account at all — creating an owner from the tenant form would go around it.

UsersRelationManager hangs the roster under the tenant's own record, read-only: an account is platform-wide, so creating, moving and deleting one belong to the Users resource, and roster membership itself is changed from the tenant's own panel.

## The tenant pages are Filament's own UI and nothing else
Standing instruction from the project owner: the platform panel's pages are built from Filament's own components only — no stylesheet, render hook or Blade partial of our own. A scoped "Material" restyle (notched labels on the field border, tonal section avatars, chips, an avatar in the heading) was built for these pages and **deleted** on that instruction. Do not bring it back; get the effect from a Filament component, or leave it. (The language switcher, `number-inputs` and the brand view predate this and stay.)

What the pages do with Filament alone, and the traps in it:
- **Type is a grouped `ToggleButtons`, not a select.** Three choices are three taps' worth of buttons rather than a list to open; each carries `TenantType::icon()`. It keeps `->enum()` and `->required()`, so a type the platform does not serve is still refused.
- **Each phone is a code and a number in one `Grid`** with `->gridContainer()` and `['default' => 1, '@sm' => 5]`, the code spanning 2 and the number 3. A phone is too narrow to give both a box apiece, so on one they stack; from a container 24rem wide they share a line. The state paths are unchanged, because a `Grid` adds none.
- **Save/Cancel are sticky and end-aligned**, set as the page's own `$formActionsAreSticky` / `$formActionsAlignment` on `EditTenant` and `CreateTenant` — redeclared on each class, because the properties are static on Filament's base page and setting them there would change every page of both panels. Filament draws `fi-align-end` as `flex-row-reverse`, so the default order (Save, then Cancel) *shows* as Cancel, Save at the right edge. Do not reorder them in PHP: that puts Save on the left.
- **The edit page's heading is the tenant's name**, its subheading `host · type · state` as one string, and its two header actions are icon buttons with tooltips. `DeleteAction` carries **no icon of its own**, so `->iconButton()` alone draws an empty, invisible button; give it `->icon()`. `TenantManagementTest` pins this.
- **The roster shows an account's email under its name** (the name column's description; that column searches both), and the whole row opens the account. A separate email column with `copyable()` was dropped for it: a copyable cell and a row link would fight over the same tap. "Belongs here" is hidden below `md`.
