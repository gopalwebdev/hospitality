<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Location;
use App\Models\User;

/**
 * Who may manage a tenant's rooms, tables and delivery points.
 *
 * location.manage throughout, the ChargePolicy shape: setting up where orders
 * go is a tenant owner's job, not floor staff's. Which tenant's locations are
 * in front of you is not this policy's business; Filament scopes the resource
 * to the panel's tenant.
 */
class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::LocationManage->value);
    }

    public function view(User $user, Location $location): bool
    {
        return $user->can(Permission::LocationManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::LocationManage->value);
    }

    public function update(User $user, Location $location): bool
    {
        return $user->can(Permission::LocationManage->value);
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->can(Permission::LocationManage->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::LocationManage->value);
    }

    /**
     * Drag locations into the order they are offered in.
     *
     * Filament asks for this by name the moment a table is reorderable, and
     * strictAuthorization refuses outright when it is missing.
     */
    public function reorder(User $user): bool
    {
        return $user->can(Permission::LocationManage->value);
    }
}
