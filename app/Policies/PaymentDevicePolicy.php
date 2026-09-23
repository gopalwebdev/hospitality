<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PaymentDevice;
use App\Models\User;

/**
 * Who may manage a tenant's card machines and QR codes.
 *
 * settings.manage throughout, the ChargePolicy shape: a card machine is
 * tenant configuration, the same audience as Charges. Which tenant's devices
 * are in front of you is not this policy's business; Filament scopes the
 * resource to the panel's tenant.
 */
class PaymentDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function view(User $user, PaymentDevice $paymentDevice): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function update(User $user, PaymentDevice $paymentDevice): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function delete(User $user, PaymentDevice $paymentDevice): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    /**
     * Drag devices into the order they are offered in.
     *
     * Filament asks for this by name the moment a table is reorderable, and
     * strictAuthorization refuses outright when it is missing.
     */
    public function reorder(User $user): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }
}
