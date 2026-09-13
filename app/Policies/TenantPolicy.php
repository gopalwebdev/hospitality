<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Restaurant;
use App\Models\User;

/**
 * Who may manage the roster of restaurants on the platform.
 *
 * restaurant.manage is a product team permission, so it is granted to no role at
 * all: in practice only a super admin passes these checks, through the
 * Gate::before in AppServiceProvider.
 */
class RestaurantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RestaurantManage->value);
    }

    public function view(User $user, Restaurant $restaurant): bool
    {
        return $user->can(Permission::RestaurantManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RestaurantManage->value);
    }

    public function update(User $user, Restaurant $restaurant): bool
    {
        return $user->can(Permission::RestaurantManage->value);
    }

    public function delete(User $user, Restaurant $restaurant): bool
    {
        return $user->can(Permission::RestaurantManage->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::RestaurantManage->value);
    }
}
