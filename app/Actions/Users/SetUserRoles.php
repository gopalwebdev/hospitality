<?php

namespace App\Actions\Users;

use App\Actions\Tenants\EnsureRoleFitsWithinLimit;
use App\Enums\Role as RoleEnum;
use App\Models\Role;
use App\Models\User;

/**
 * Set the roles an account holds, from the product team panel.
 *
 * Unlike SetTenantUserRoles this withholds nothing: a super admin is
 * exactly who decides that a role carrying a product team permission may be handed
 * out, and it is the only place that decision can be made.
 *
 * Roles go through Spatie's syncRoles() rather than the pivot so the permission
 * registrar's cache is flushed with them. A bare pivot sync would leave every
 * can() check for the rest of the request answering from the old set.
 */
class SetUserRoles
{
    public function __construct(private readonly EnsureRoleFitsWithinLimit $ensureRoleFits) {}

    /**
     * @param  list<string>  $roleNames
     */
    public function __invoke(User $user, array $roleNames): void
    {
        $roles = Role::query()->whereIn('name', $roleNames)->get();

        // A tenant panel refuses to touch the roles of someone who staffs
        // more than one tenant, because a role is held per account and
        // would change what they can do everywhere at once (see
        // .ai/rules/tenants.md) — this panel is exactly where that call
        // is made, so it checks every tenant the grant would apply to,
        // not just one.
        foreach ($user->tenants()->get() as $tenant) {
            foreach ($roles as $role) {
                $roleEnum = RoleEnum::tryFrom($role->name);

                if ($roleEnum instanceof RoleEnum) {
                    ($this->ensureRoleFits)($tenant, $roleEnum, $user);
                }
            }
        }

        $user->syncRoles($roles);
    }
}
