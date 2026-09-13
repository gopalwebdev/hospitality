<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Charge;
use App\Models\User;

/**
 * Who may change what is added to a guest's bill.
 *
 * settings.manage for looking as well as changing: charges moved here from the
 * Settings page, and the people who could change them there are the people who
 * can here — a tenant owner, never floor staff. Which tenant's charges are in
 * front of you is not this policy's business; Filament scopes the resource to
 * the panel's tenant.
 */
class ChargePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function view(User $user, Charge $charge): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function update(User $user, Charge $charge): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function delete(User $user, Charge $charge): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    /**
     * Drag charges into the order a guest reads them in.
     *
     * Filament asks for this by name the moment a table is reorderable, and
     * strictAuthorization refuses outright when it is missing.
     */
    public function reorder(User $user): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }
}
