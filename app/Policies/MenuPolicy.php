<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Menu;
use App\Models\User;

/**
 * Who may shape a tenant's menus.
 *
 * The same split as MenuCategoryPolicy and MenuItemPolicy, because it is the
 * same job at a different level: reading is menu.view, which staff hold too;
 * changing anything is menu.manage, which only a tenant admin has. Which
 * tenant's menus are in front of you is not this policy's business —
 * Filament scopes the resource to the panel's tenant, and the observers refuse
 * a row whose parent belongs to another tenant.
 */
class MenuPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::MenuView->value);
    }

    public function view(User $user, Menu $menu): bool
    {
        return $user->can(Permission::MenuView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    public function update(User $user, Menu $menu): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    public function delete(User $user, Menu $menu): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::MenuManage->value);
    }

    /**
     * Drag menus into a new order.
     *
     * Filament asks for this by name the moment a table is reorderable, and
     * strictAuthorization refuses outright when it is missing — see
     * .ai/rules/policies.md.
     */
    public function reorder(User $user): bool
    {
        return $user->can(Permission::MenuManage->value);
    }
}
