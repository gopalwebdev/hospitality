<?php

use App\Enums\FilamentPanel;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('makes the product team panel installable from its sign-in page on', function (): void {
    $this->get('http://hospitality.test/dashboard/login')
        ->assertOk()
        ->assertSee('<link rel="manifest" href="http://hospitality.test/dashboard/manifest.webmanifest">', escape: false)
        ->assertSee('service-worker.js', escape: false);

    $this->actingAs(User::factory()->admin()->create())
        ->get('http://hospitality.test/dashboard')
        ->assertOk()
        ->assertSee('http://hospitality.test/dashboard/manifest.webmanifest', escape: false);
});

it("makes a tenant's panel installable once a tenant is known", function (): void {
    $tenant = Tenant::factory()->create(['slug' => 't1']);
    $owner = User::factory()->create();
    $owner->tenants()->attach($tenant);
    $owner->assignRole(Role::Owner->value);

    // The sign-in page has no tenant yet, so there is no app to name.
    $this->get('http://t1.hospitality.test/dashboard/login')
        ->assertOk()
        ->assertDontSee('rel="manifest"', escape: false);

    $this->actingAs($owner)
        ->get('http://t1.hospitality.test/dashboard')
        ->assertOk()
        ->assertSee('<link rel="manifest" href="http://t1.hospitality.test/dashboard/manifest.webmanifest">', escape: false)
        ->assertSee('service-worker.js', escape: false);
});

it('serves each panel a manifest scoped to /dashboard', function (): void {
    Tenant::factory()->create(['slug' => 't1', 'name' => 'Spice Garden']);

    $this->get('http://hospitality.test/dashboard/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('name', 'Hospitality Platform')
        ->assertJsonPath('start_url', '/dashboard')
        ->assertJsonPath('scope', '/dashboard')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('theme_color', FilamentPanel::Platform->themeColor())
        ->assertJsonCount(3, 'icons');

    // Named apart from the guest app on the same subdomain, which installs as
    // the bare "Spice Garden".
    $this->get('http://t1.hospitality.test/dashboard/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('name', 'Spice Garden Dashboard')
        ->assertJsonPath('start_url', '/dashboard')
        ->assertJsonPath('scope', '/dashboard')
        ->assertJsonPath('theme_color', FilamentPanel::Tenant->themeColor());
});

it('serves a worker that caches nothing and may control all of /dashboard', function (string $url): void {
    Tenant::factory()->create(['slug' => 't1']);

    $response = $this->get($url)
        ->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/dashboard');

    // A panel page is a signed-in Livewire page, and an old copy of one is
    // worse than none.
    expect((string) $response->headers->get('Content-Type'))->toContain('javascript')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->getContent())->not->toContain('fetch')->not->toContain('caches');
})->with([
    'product team' => 'http://hospitality.test/dashboard/service-worker.js',
    'tenant' => 'http://t1.hospitality.test/dashboard/service-worker.js',
]);

it("keeps a switched-off tenant's panel installable, and answers for no tenant that does not exist", function (): void {
    Tenant::factory()->create(['slug' => 't1', 'is_active' => false]);

    // Switching a tenant off takes down its guest app, not its panel.
    $this->get('http://t1.hospitality.test/dashboard/manifest.webmanifest')->assertOk();
    $this->get('http://t1.hospitality.test/dashboard/service-worker.js')->assertOk();

    $this->get('http://nobody.hospitality.test/dashboard/manifest.webmanifest')->assertNotFound();
    $this->get('http://nobody.hospitality.test/dashboard/service-worker.js')->assertNotFound();
});
