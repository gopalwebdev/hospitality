<?php

use App\Enums\FilamentPanel;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full application and run against a migrated,
| transaction-wrapped database. Unit tests stay free of the framework so
| they remain fast and isolated.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->afterEach(function (): void {
    File::deleteDirectory(storage_path('logs/testing'));
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Redirect the one-time password log channel into a file this test owns.
 *
 * Sign-in codes are delivered by being written to a log, so reading them back
 * out of one is how a test walks through the real sign-in flow.
 *
 * @return Closure(): list<string> a reader returning every code logged so far
 */
function captureIssuedCodes(): Closure
{
    $path = storage_path('logs/testing/otp-'.Str::random(8).'.log');

    // Codes reach people by email; the log copy is opt-in and off by default.
    config()->set('otp.log_codes', true);

    config()->set('logging.channels.otp', [
        'driver' => 'single',
        'path' => $path,
    ]);

    Log::forgetChannel('otp');

    return function () use ($path): array {
        if (! file_exists($path)) {
            return [];
        }

        preg_match_all('/"code":"(\d+)"/', (string) file_get_contents($path), $matches);

        return $matches[1];
    };
}

/**
 * Sign in as a user of the given tenant and put the panel in that
 * tenant's context, the way a request to its subdomain would.
 */
function enterTenantPanel(Tenant $tenant, Role $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->tenants()->attach($tenant);

    test()->actingAs($user);
    Filament::setCurrentPanel(FilamentPanel::Tenant->value);

    // Booting is what registers the tenancy global scopes, which a request
    // gets from Filament's middleware. Without it a Livewire test would see
    // every tenant's records and prove nothing about tenant isolation.
    Filament::bootCurrentPanel();
    Filament::setTenant($tenant);

    return $user;
}

/**
 * Sign in as a member of the product team, with their panel current.
 */
function enterProductTeamPanel(): User
{
    $user = User::factory()->superAdmin()->create();

    test()->actingAs($user);
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    Filament::bootCurrentPanel();

    return $user;
}
