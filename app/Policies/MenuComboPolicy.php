<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MenuCombo;
use App\Models\User;

/**
 * Who may put together the bundles a menu is sold with.
 *
 * Reading is menu.view, which staff and guests hold too; changing anything is
 * menu.manage, which only a tenant admin has. Which tenant's records
 * are in front of you is not this policy's business — Filament scopes the
 * relation manager to the panel's tenant, and the observers refuse a row whose
 * parent belongs to another tenant.
 */
class MenuComboPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::MenuView->value);
    }

    public function view(User $user, MenuCombo $menuCombo): bool
    {
        return $user->can(Permission::MenuView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    public function update(User $user, MenuCombo $menuCombo): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    public function delete(User $user, MenuCombo $menuCombo): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    /**
     * Drag rows into a new order on the menu.
     *
     * Rearranging the menu is changing it, so this is menu.manage like the
     * rest. Filament asks for this by name the moment a table is reorderable,
     * and strictAuthorization refuses outright when it is missing.
     */
    public function reorder(User $user): bool
    {
        return $user->can(Permission::MenuManage->value);
    }
}
