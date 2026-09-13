<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Who may manage the people attached to a tenant.
 *
 * This is the tenant's own roster: user.manage is held by the Owner and
 * Manager roles, and the panel only ever shows users of the tenant whose
 * subdomain is being served, because Filament scopes the resource to the
 * current tenant.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UserManage->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->can(Permission::UserManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::UserManage->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(Permission::UserManage->value);
    }

    /**
     * Delete an account outright.
     *
     * Accounts are platform-wide, so only the product team may remove one; a
     * tenant takes someone off its own roster instead, which is what
     * removeFromTenant() below is for.
     *
     * Nobody may delete their own account. UserResource states that rule again
     * because Gate::before answers this method true for an admin before it
     * ever runs.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->isAdmin() && ! $user->is($model);
    }

    /**
     * Take a user off this tenant's roster.
     *
     * Nobody may remove themselves: it is the one move that can leave a
     * tenant with no way back into its own panel.
     */
    public function removeFromTenant(User $user, User $model): bool
    {
        return $user->can(Permission::UserManage->value) && ! $user->is($model);
    }
}
