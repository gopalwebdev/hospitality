<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MenuItem;
use App\Models\User;

/**
 * Who may change what a tenant sells.
 *
 * Reading is menu.view, which staff and guests hold too; changing anything is
 * menu.manage, which only a tenant owner has. Which tenant's items
 * are in front of you is not this policy's business — Filament scopes the
 * resource to the panel's tenant, and the observers refuse a row whose parent
 * belongs to another tenant.
 */
class MenuItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::MenuView->value);
    }

    public function view(User $user, MenuItem $menuItem): bool
    {
        return $user->can(Permission::MenuView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    public function update(User $user, MenuItem $menuItem): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    public function delete(User $user, MenuItem $menuItem): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::MenuManage->value);
    }
}
