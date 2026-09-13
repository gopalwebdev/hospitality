<?php

namespace App\Policies;

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\User;

/**
 * Who may define the permissions roles are built from.
 *
 * permission.manage is a product team permission granted to no role, so only an
 * admin passes. As with roles, the built-in guard lives on the resource
 * rather than here: Gate::before answers before a policy ever runs.
 */
class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionEnum::PermissionManage->value);
    }

    public function view(User $user, Permission $permission): bool
    {
        return $user->can(PermissionEnum::PermissionManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionEnum::PermissionManage->value);
    }

    public function update(User $user, Permission $permission): bool
    {
        return $user->can(PermissionEnum::PermissionManage->value) && ! $permission->isBuiltIn();
    }

    /**
     * A permission is only deleted when no role holds it, and when it is not
     * one the code declares. Gate::before waves an admin past this, which
     * is why PermissionResource states the same rule again.
     */
    public function delete(User $user, Permission $permission): bool
    {
        return $user->can(PermissionEnum::PermissionManage->value) && $permission->undeletableReason() === null;
    }
}
