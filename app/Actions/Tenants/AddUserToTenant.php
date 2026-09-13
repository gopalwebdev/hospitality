<?php

namespace App\Actions\Tenants;

use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Put someone on a tenant's roster.
 *
 * An address that already has an account joins on that account rather than
 * getting a second one: accounts are platform-wide, and one person may staff
 * more than one tenant.
 */
class AddUserToTenant
{
    public function __construct(private readonly SetTenantUserRoles $setRoles) {}

    /**
     * @param  list<string>  $roleNames
     */
    public function __invoke(Tenant $tenant, string $name, string $email, array $roleNames = []): User
    {
        // Accounts are platform-wide, so this looks past the panel's tenant
        // scope: the address may already have an account at another
        // tenant, and creating a second one would collide on the unique
        // email anyway.
        $user = User::query()
            ->withoutGlobalScope(Filament::getTenancyScopeName())
            ->withEmail($email)
            ->first();

        if (! $user instanceof User) {
            // A brand new account belongs to the tenant that opened it.
            // An existing one keeps whichever tenant it already had: it may
            // staff several, and this panel does not get to move it.
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'tenant_id' => $tenant->getKey(),
            ]);
        }

        $tenant->users()->syncWithoutDetaching([$user->getKey()]);

        ($this->setRoles)($user->refresh(), $roleNames);

        // Already fresh: syncing roles resets the relation it changed, and a
        // second refresh would only read the same row again.
        return $user;
    }
}
