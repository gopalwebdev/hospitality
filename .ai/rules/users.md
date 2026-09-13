---
paths:
  - 'app/Filament/Platform/Resources/Users/**'
---

# Users

## Where an account belongs is settled once, and never crosses to the product team
`tenant_id` is chosen when an account is opened and is read-only afterwards: UserForm disables the Tenant select on edit and does not dehydrate it, so a submitted change is dropped rather than applied. Moving an account between tenants would carry its roles across with it (roles are per account — see .ai/rules/tenants.md), and moving one to the product team would hand the whole platform to a single tenant's admin.

That second half is a hard invariant, not just a form rule: `User::booted()` throws a LogicException on saving when `is_super_admin` is true and `tenant_id` is not null. The product team belong to no tenant at all. The toggle is disabled in the form whenever a tenant is chosen, so the panel never offers the combination; the model guard is the backstop for anything that goes around the resource.

Nobody may clear their own product team flag either — that is the one change that can lock the last super admin out.
