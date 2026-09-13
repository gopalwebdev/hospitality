<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\HomeRow;
use App\Models\User;

/**
 * Who may arrange the rows of the home screen guests land on.
 *
 * Its own pair of permissions rather than the menu's: the home screen is the
 * shop window, and a tenant may well want someone who can rearrange it
 * without also being able to rewrite prices. storefront.view to look,
 * storefront.manage to change anything.
 */
class HomeRowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::StorefrontView->value);
    }

    public function view(User $user, HomeRow $homeRow): bool
    {
        return $user->can(Permission::StorefrontView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::StorefrontManage->value);
    }

    public function update(User $user, HomeRow $homeRow): bool
    {
        return $user->can(Permission::StorefrontManage->value);
    }

    public function delete(User $user, HomeRow $homeRow): bool
    {
        return $user->can(Permission::StorefrontManage->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::StorefrontManage->value);
    }

    /**
     * Drag rows into a new order on the home screen.
     *
     * The order is the whole point of the page, so this is the first thing a
     * tenant does here. Filament asks for it by name because the table is
     * reorderable, and strictAuthorization refuses when it is missing — see
     * .ai/rules/policies.md.
     */
    public function reorder(User $user): bool
    {
        return $user->can(Permission::StorefrontManage->value);
    }
}
