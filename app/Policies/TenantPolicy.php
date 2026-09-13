<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Tenant;
use App\Models\User;

/**
 * Who may manage the roster of tenants on the platform.
 *
 * tenant.manage is a product team permission, so it is granted to no role at
 * all: in practice only an admin passes these checks, through the
 * Gate::before in AppServiceProvider.
 */
class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::TenantManage->value);
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::TenantManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::TenantManage->value);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::TenantManage->value);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::TenantManage->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::TenantManage->value);
    }
}
