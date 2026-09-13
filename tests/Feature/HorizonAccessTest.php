<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Horizon dashboard access
|--------------------------------------------------------------------------
|
| The queue carries jobs for every tenant on the platform, so the
| dashboard is the product team only.
|
*/

it('lets a super admin open Horizon', function (): void {
    $user = User::factory()->superAdmin()->create();

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();
});

it('keeps a tenant admin out of Horizon', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->assignRole(Role::Admin->value);
    $user->tenants()->attach($tenant);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

it('keeps a guest out of Horizon', function (): void {
    expect(Gate::allows('viewHorizon'))->toBeFalse();
});

it('sends sign-in mail to its own queue so codes are not stuck behind other work', function (): void {
    $queues = config('horizon.defaults.supervisor-1.queue');

    expect($queues)->toContain('mail')
        ->and($queues[0])->toBe('mail');
});
