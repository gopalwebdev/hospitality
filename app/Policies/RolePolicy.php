<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Who may define the roles tenants assign from.
 *
 * role.manage is a product team permission granted to no role, so only a super
 * admin passes. Whether a *particular* role may be edited is a separate
 * question — a built-in role is owned by App\Enums\Role and the seeder — and
 * that guard lives on the resource, because Gate::before lets a super admin
 * past any policy check before it runs.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RoleManage->value);
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can(Permission::RoleManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RoleManage->value);
    }

    /**
     * Every role's permissions are editable, built-in ones included. What is
     * withheld is the name, and the form is where that is disabled — a policy
     * cannot say "this record, but only some of its columns".
     */
    public function update(User $user, Role $role): bool
    {
        return $user->can(Permission::RoleManage->value);
    }

    /**
     * A role is only deleted when nothing depends on it: not built in, held by
     * nobody, and holding no permissions. Gate::before waves a super admin past
     * this, which is why RoleResource states the same rule again.
     */
    public function delete(User $user, Role $role): bool
    {
        return $user->can(Permission::RoleManage->value) && $role->undeletableReason() === null;
    }
}
