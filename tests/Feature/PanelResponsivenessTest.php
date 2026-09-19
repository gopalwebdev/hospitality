<?php

use App\Enums\Role;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| Both panels open at every width
|--------------------------------------------------------------------------
|
| Staff install a panel as a PWA on a phone or a tablet, so a panel page has
| to render for them. A `desktop-only.blade.php` used to cover both panels
| below 1024px with "open this on a laptop", which made an installed panel on
| a phone open to a dead end. It is gone, and this is what keeps it gone.
|
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('serves the tenant panel with no screen-size gate over it', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Owner);

    $html = (string) $this->get('http://'.$tenant->slug.'.hospitality.test/dashboard')
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('panel-needs-a-bigger-screen')
        ->and($html)->not->toContain('Open this on a laptop');
});

it('serves the platform panel with no screen-size gate over it', function (): void {
    enterProductTeamPanel();

    $html = (string) $this->get('http://hospitality.test/dashboard')
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('panel-needs-a-bigger-screen')
        ->and($html)->not->toContain('Open this on a laptop');
});

it('keeps the view that gated a panel by width deleted', function (): void {
    // Named rather than only asserted through the rendered page: someone
    // restoring the file would otherwise have to also re-hang the render hook
    // before anything here failed.
    expect(view()->exists('filament.desktop-only'))->toBeFalse();
});

it('still lets a phone install the panel it can now open', function (): void {
    $tenant = Tenant::factory()->create();

    // The manifest was always phone-ready — standalone, no orientation lock.
    // The gate was the only thing making the install pointless.
    $this->get('http://'.$tenant->slug.'.hospitality.test/dashboard/manifest.webmanifest')
        ->assertOk()
        ->assertJsonPath('display', 'standalone');
});
