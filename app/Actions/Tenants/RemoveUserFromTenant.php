<?php

namespace App\Actions\Tenants;

use App\Models\Tenant;
use App\Models\User;

/**
 * Take someone off a tenant's roster.
 *
 * The account itself is left alone: it is platform-wide, may staff other
 * tenants, and a tenant panel has no business deleting it. Someone
 * detached from their last tenant keeps their account and simply has no
 * panel to enter, because User::canAccessPanel() finds no tenant.
 */
class RemoveUserFromTenant
{
    public function __invoke(Tenant $tenant, User $user): void
    {
        $tenant->users()->detach($user->getKey());
    }
}
