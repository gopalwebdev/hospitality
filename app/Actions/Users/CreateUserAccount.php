<?php

namespace App\Actions\Users;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AccountCreatedNotification;

/**
 * Open an account from the product team panel.
 *
 * The tenant column and the tenant roster are written together: the column
 * says which tenant the account belongs to, and the roster is what actually
 * lets them into that tenant's panel. Setting one without the other would
 * show a tenant on a row for someone who cannot open it.
 *
 * A null tenant leaves the account belonging to the platform. That alone
 * grants nothing — is_super_admin is what does — so it is passed separately.
 */
class CreateUserAccount
{
    public function __construct(private readonly SetUserRoles $setRoles) {}

    /**
     * @param  list<string>  $roleNames
     */
    public function __invoke(
        string $name,
        string $email,
        ?int $tenantId = null,
        bool $isSuperAdmin = false,
        array $roleNames = [],
    ): User {
        $tenant = $tenantId === null
            ? null
            : Tenant::query()->find($tenantId);

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'tenant_id' => $tenant?->getKey(),
            'is_super_admin' => $isSuperAdmin,
        ]);

        if ($tenant instanceof Tenant) {
            $user->tenants()->syncWithoutDetaching([$tenant->getKey()]);
        }

        ($this->setRoles)($user, $roleNames);

        $user->notify(new AccountCreatedNotification(
            $this->signInUrlFor($tenant),
            $tenant?->name,
        ));

        return $user->refresh();
    }

    /**
     * Where this account signs in: /login on their tenant's subdomain, or
     * on the root domain when they belong to the product team.
     */
    private function signInUrlFor(?Tenant $tenant): string
    {
        if ($tenant instanceof Tenant) {
            return $tenant->signInUrl();
        }

        return route('platform.login');
    }
}
