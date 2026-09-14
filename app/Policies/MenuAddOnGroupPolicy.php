<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MenuAddOnGroup;
use App\Models\User;

/**
 * Who may shape the choices items are customised with.
 *
 * Reading is menu.view, which staff and guests hold too; changing anything is
 * menu.manage, which only a tenant owner has. Which tenant's groups are in front
 * of you is not this policy's business — Filament scopes the resource to the
 * panel's tenant, and the observers refuse an option or a link that crosses tenants.
 */
class MenuAddOnGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::MenuView->value);
    }

    public function view(User $user, MenuAddOnGroup $menuAddOnGroup): bool
    {
        return $user->can(Permission::MenuView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    public function update(User $user, MenuAddOnGroup $menuAddOnGroup): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    public function delete(User $user, MenuAddOnGroup $menuAddOnGroup): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::MenuManage->value);
    }
}
