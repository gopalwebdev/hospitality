<?php

namespace App\Actions\Tenants;

use App\Enums\Role as RoleEnum;
use App\Models\Tenant;
use App\Models\Role;
use App\Models\User;

/**
 * Set the roles of someone on a tenant's roster.
 *
 * This is the only path a tenant panel takes to a user's roles, and it
 * holds the rules that make that safe.
 */
class SetTenantUserRoles
{
    public function __construct(private readonly EnsureRoleFitsWithinLimit $ensureRoleFits) {}

    /**
     * @param  list<string>  $roleNames
     */
    public function __invoke(User $user, array $roleNames): void
    {
        // Roles are held per account rather than per tenant, so setting
        // them for someone who staffs more than one tenant would change
        // what they can do at the others. That stays a super admin's call.
        if ($user->staffsSeveralTenants()) {
            return;
        }

        // Only roles a tenant may hand out, whatever arrived in the form:
        // a role carrying a product team permission would mint the product team.
        $assignable = Role::query()
            ->assignableWithinTenant()
            ->whereIn('name', $roleNames)
            ->get();

        // A brand new account, mid-way through being added to its first
        // tenant, has none yet — nothing to check the grant against.
        $tenant = $user->tenants()->first();

        if ($tenant instanceof Tenant) {
            foreach ($assignable as $role) {
                $roleEnum = RoleEnum::tryFrom($role->name);

                if ($roleEnum instanceof RoleEnum) {
                    ($this->ensureRoleFits)($tenant, $roleEnum, $user);
                }
            }
        }

        // The models already loaded above, so Spatie does not look each one up
        // by name a second time.
        $user->syncRoles($assignable);
    }
}
