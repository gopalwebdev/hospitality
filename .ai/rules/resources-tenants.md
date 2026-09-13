---
paths:
  - 'app/Filament/Platform/Resources/Tenants/**'
---

# Resources Tenants

## Onboarding a tenant leads into creating its admin
TenantForm reads top to bottom as one sequence — Identity, Limits, Where it trades, How the platform reaches them — in a single column of full-width sections. A two-column grid of sections put the address beside the name, which read as two unrelated starting points.

Identity includes the tenant's **type** (`App\Enums\TenantType`). It has no default, so onboarding has to choose, and it stays editable afterwards on the project owner's decision — nothing depends on it yet, and no copy varies by it. The table shows it as a badge and filters on it.

CreateTenant does not return to the list. A tenant with nobody on its roster cannot be opened by anyone, so it redirects to `UserResource::getUrl('create', ['tenant_id' => ..., 'role' => 'admin'])` and CreateUser's `fillForm()` reads those two query parameters back. The account is deliberately made on the Users page rather than inline here, because that page holds the one-time code confirmation that authorises opening an account at all — creating an admin from the tenant form would go around it.

UsersRelationManager hangs the roster under the tenant's own record, read-only: an account is platform-wide, so creating, moving and deleting one belong to the Users resource, and roster membership itself is changed from the tenant's own panel.
