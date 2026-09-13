---
paths:
  - 'app/Actions/Tenants/**'
---

# Tenants

## Roles are held per account, not per tenant
Spatie teams are off, so a user's roles are global to their account. Someone staffing two tenants has one set of roles, and changing them from one tenant panel would change what they can do at the other. SetTenantUserRoles is the only path a tenant panel takes to roles: it refuses when the user staffs more than one tenant, and filters to Role::assignableWithinTenant() so a role carrying a product team permission (tenant.manage, role.manage, permission.manage) can never be assigned from a tenant panel, whatever the form submits. Scoping roles per tenant means enabling spatie teams; do not work around this rule instead.
