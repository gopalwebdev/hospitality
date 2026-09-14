---
paths:
  - 'app/Providers/Filament/*.php'
  - app/Providers/Filament/TenantPanelProvider.php
---

# Providers Filament

## Both panels run with databaseTransactions() enabled
Filament's `hasDatabaseTransactions()` defaults to false, so a CreateRecord/EditRecord page runs `handleRecordCreation()`/`handleRecordUpdate()` with no transaction by default. A ValidationException thrown partway through a multi-step write (e.g. EnsureRoleFitsWithinLimit, thrown after the user row and roster attachment are already written but before roles sync) used to leave the earlier writes committed — a "refused" account that still existed on the roster with no role.

Both TenantPanelProvider and PlatformPanelProvider now call ->databaseTransactions(), so any exception thrown during a save rolls back the whole thing. Do not remove this without re-checking every action class invoked from a Create/Edit page's handleRecordCreation/handleRecordUpdate for multi-step writes that assume atomicity.

## The tenant panel brands itself with the signed-in tenant, and has no tenant menu
TenantPanelProvider::brandName() resolves per request: the signed-in tenant's own name once Filament::getTenant() is known, and FilamentPanel::Tenant->brandName() (the generic panel name, from config('app.name')) before that — the sign-in page has no tenant yet. Both ->brandName() and the ->brandLogo() view (resources/views/filament/brand.blade.php, via its optional $name param) read from the same private brandName() helper.

->tenantMenu(false) is also set: a tenant owner has nowhere to switch to (the panel is scoped to one tenant), and an admin supporting one opens it from the "Open dashboard" row action on the platform panel's Tenants table instead (TenantsTable.php), which links to Tenant::signInUrl(). Sessions are per-domain (.ai/rules/middleware.md), so this was never a same-session switch even when the tenant menu was on — removing it loses no real capability.

## PlatformPanelProvider registers before TenantPanelProvider
Both panels are served under `/dashboard`, and the order in `bootstrap/providers.php` decides which one the root domain's `/dashboard/login` belongs to — see `.ai/rules/filament.md`. Both providers also run `->spa(hasPrefetching: true)`.

## Both sidebars collapse to their icons
Both providers call `->sidebarCollapsibleOnDesktop()`: the project owner found the navigation took too much of the screen, and the menu page and the items table want the width. It folds to an icon rail rather than disappearing (`sidebarFullyCollapsibleOnDesktop()`), so every page stays one click away. Navigation groups carry no icons of their own on purpose — Filament hides item icons in the expanded sidebar once a group has one.
