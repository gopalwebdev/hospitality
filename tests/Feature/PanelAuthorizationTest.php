<?php

use App\Enums\FilamentPanel;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Pages\Settings;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Every page is reached through a permission
|--------------------------------------------------------------------------
|
| Both panels run with strictAuthorization(), so a resource with no policy is
| refused rather than waved through. These tests are the other half of that:
| they walk whatever each panel actually has registered and check that someone
| holding nothing is refused, so a page added later cannot quietly ship open.
|
| The Dashboard is exempt on purpose. It is the panel's landing page and holds
| no data of its own; who may reach the panel at all is User::canAccessPanel().
|
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Every resource and page a panel has registered, minus the landing dashboard.
 *
 * @return list<class-string>
 */
function gatedPagesOf(FilamentPanel $panel): array
{
    $registered = Filament::getPanel($panel->value);

    return array_values(array_filter(
        [...$registered->getResources(), ...$registered->getPages()],
        static fn (string $class): bool => ! is_a($class, Dashboard::class, allow_string: true),
    ));
}

it('refuses every product team page to an account holding nothing', function (): void {
    $powerless = User::factory()->create();

    $this->actingAs($powerless);
    Filament::setCurrentPanel(FilamentPanel::Platform->value);
    Filament::bootCurrentPanel();

    $pages = gatedPagesOf(FilamentPanel::Platform);

    expect($pages)->not->toBeEmpty();

    foreach ($pages as $page) {
        expect($page::canAccess())->toBeFalse("{$page} is open to an account with no permissions");
    }
});

it('refuses every tenant page to someone on the roster holding no role', function (): void {
    $tenant = Tenant::factory()->create();
    $powerless = User::factory()->ofTenant($tenant)->create();

    $this->actingAs($powerless);
    Filament::setCurrentPanel(FilamentPanel::Tenant->value);
    Filament::bootCurrentPanel();
    Filament::setTenant($tenant);

    $pages = gatedPagesOf(FilamentPanel::Tenant);

    expect($pages)->not->toBeEmpty();

    foreach ($pages as $page) {
        expect($page::canAccess())->toBeFalse("{$page} is open to a roster member with no role");
    }
});

it('opens every product team page to the product team', function (): void {
    enterProductTeamPanel();

    foreach (gatedPagesOf(FilamentPanel::Platform) as $page) {
        expect($page::canAccess())->toBeTrue("{$page} is closed to the product team");
    }
});

it('opens every tenant page to a tenant admin', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Admin);

    foreach (gatedPagesOf(FilamentPanel::Tenant) as $page) {
        expect($page::canAccess())->toBeTrue("{$page} is closed to a tenant admin");
    }
});

it('reflects a permission being taken off a role straight away', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = enterTenantPanel($tenant, RoleEnum::Admin);

    $settings = Settings::class;

    expect($settings::canAccess())->toBeTrue();

    // The product team may edit what any role grants, so a page's availability
    // has to follow that within the same request rather than at deploy time.
    Role::findByName(RoleEnum::Admin->value)
        ->revokePermissionTo(PermissionEnum::SettingsManage->value);

    expect($admin->fresh()->can(PermissionEnum::SettingsManage->value))->toBeFalse();

    $this->actingAs($admin->fresh());

    expect($settings::canAccess())->toBeFalse();
});

it('names a permission for every resource it registers', function (): void {
    // Each resource is gated by its model's policy, and every one of those
    // policies asks a permission rather than answering true. Reaching a page is
    // therefore always a question about permissions, never about a class name.
    foreach (gatedPagesOf(FilamentPanel::Platform) as $page) {
        if (! is_a($page, Resource::class, allow_string: true)) {
            continue;
        }

        $model = $page::getModel();

        expect(Gate::getPolicyFor($model))->not->toBeNull("{$page} has no policy, so strictAuthorization would refuse it outright");
    }
});
