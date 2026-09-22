<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TaxCode;
use App\Models\User;

/**
 * Who may keep this tenant's list of HSN and SAC codes.
 *
 * `settings.manage` for looking as well as changing, exactly as ChargePolicy
 * reads: a rate on a bill is an owner's business, never floor staff's.
 *
 * The one thing this adds over ChargePolicy is the catalogue. A row with no
 * tenant belongs to the product team and is offered to everyone, so a tenant
 * may read it and may not change or delete it — otherwise one tenant editing a
 * shared row would silently reprice every other tenant's next item.
 */
class TaxCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function view(User $user, TaxCode $taxCode): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }

    public function update(User $user, TaxCode $taxCode): bool
    {
        return ! $taxCode->isFromCatalogue() && $user->can(Permission::SettingsManage->value);
    }

    public function delete(User $user, TaxCode $taxCode): bool
    {
        return ! $taxCode->isFromCatalogue() && $user->can(Permission::SettingsManage->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::SettingsManage->value);
    }
}
