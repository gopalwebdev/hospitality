<?php

use App\Enums\FilamentPanel;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Panel resolution
|--------------------------------------------------------------------------
|
| Both panels live under /dashboard and are told apart only by host, so these tests
| pin the behaviour that the root domain reaches the product team panel and a
| tenant subdomain reaches that tenant's panel.
|
*/

it('serves the product team panel on the root domain', function (): void {
    $this->get('http://tenant-app.test/dashboard/login')
        ->assertOk()
        ->assertSee('Tenant Platform');
});

it('serves each panel from the path its enum declares', function (FilamentPanel $panel): void {
    expect(Filament::getPanel($panel->value)->getPath())->toBe($panel->path());
})->with(FilamentPanel::cases());

it('sends a guest on the product team panel to its own sign-in', function (): void {
    $this->get('http://tenant-app.test/dashboard/tenants')
        ->assertRedirect('http://tenant-app.test/dashboard/login');
});

it('does not serve the product team panel from a tenant subdomain', function (): void {
    Tenant::factory()->create(['slug' => 't1']);

    // Same path, other host: /dashboard on a subdomain is that tenant's panel,
    // and the product team's pages are not part of it.
    $this->get('http://t1.tenant-app.test/dashboard/login')
        ->assertOk()
        ->assertDontSee('Tenant Platform');

    $this->get('http://t1.tenant-app.test/dashboard/tenants')->assertNotFound();
});

it('serves the tenant panel on a tenant subdomain', function (): void {
    Tenant::factory()->create(['slug' => 't1']);

    $this->get('http://t1.tenant-app.test/dashboard/login')
        ->assertOk()
        ->assertDontSee('Tenant Platform');
});

it('serves both panels under /dashboard, told apart by host', function (): void {
    $tenant = Tenant::factory()->make(['slug' => 't1']);

    // The tenant panel's own sign-in route carries no domain — nobody has
    // a tenant before signing in — so the root domain's /dashboard/login is the
    // product team's, and a tenant's link is its subdomain's /login.
    expect(route('filament.platform.auth.login'))
        ->toBe('http://tenant-app.test/dashboard/login')
        ->and($tenant->signInUrl())
        ->toBe('http://t1.tenant-app.test/login');
});

it('sends someone signed out from /login to the sign-in page of the panel on that host', function (): void {
    Tenant::factory()->create(['slug' => 't1']);

    $this->get('http://tenant-app.test/login')
        ->assertRedirect('http://tenant-app.test/dashboard/login');

    $this->get('http://t1.tenant-app.test/login')
        ->assertRedirect('http://t1.tenant-app.test/dashboard/login');
});

it('sends someone already signed in from /login straight to /dashboard', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 't1']);
    $productTeam = User::factory()->superAdmin()->create();

    // Every account is made before the first request: a request into the
    // tenant panel boots its tenancy, which attaches any user created after
    // it to that tenant (.ai/rules/models.md).
    $members = array_map(function (Role $role) use ($tenant): User {
        $member = User::factory()->create();
        $member->tenants()->attach($tenant);
        $member->assignRole($role->value);

        return $member;
    }, [Role::Admin, Role::Staff]);

    $this->actingAs($productTeam)
        ->get('http://tenant-app.test/login')
        ->assertRedirect('http://tenant-app.test/dashboard');

    // The tenant panel is one door for everyone who works there: staff
    // arrive exactly where admins do.
    foreach ($members as $member) {
        $this->actingAs($member)
            ->get('http://t1.tenant-app.test/login')
            ->assertRedirect('http://t1.tenant-app.test/dashboard');

        $this->actingAs($member)
            ->get('http://t1.tenant-app.test/dashboard')
            ->assertOk();
    }
});

/*
|--------------------------------------------------------------------------
| Panel authorisation
|--------------------------------------------------------------------------
*/

it('lets a super admin into the product team panel', function (): void {
    $user = User::factory()->superAdmin()->create();

    $this->actingAs($user)
        ->get('http://tenant-app.test/dashboard')
        ->assertOk();
});

it('gates the product team panel on the is_super_admin column alone', function (): void {
    $user = User::factory()->create();

    // Every tenant role there is, and still no way in.
    foreach (Role::cases() as $role) {
        $user->assignRole($role->value);
    }

    $this->actingAs($user)
        ->get('http://tenant-app.test/dashboard')
        ->assertForbidden();

    $user->forceFill(['is_super_admin' => true])->save();

    $this->actingAs($user->fresh())
        ->get('http://tenant-app.test/dashboard')
        ->assertOk();
});

it('keeps a tenant admin out of the product team panel', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 't1']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);
    $user->assignRole(Role::Admin->value);

    $this->actingAs($user)
        ->get('http://tenant-app.test/dashboard')
        ->assertForbidden();
});

it('lets a tenant admin into their own tenant panel', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 't1']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);
    $user->assignRole(Role::Admin->value);

    $this->actingAs($user)
        ->get('http://t1.tenant-app.test/dashboard')
        ->assertOk();
});

it('stops a tenant admin reaching another tenant by changing the subdomain', function (): void {
    $own = Tenant::factory()->create(['slug' => 't1']);
    Tenant::factory()->create(['slug' => 't2']);

    $user = User::factory()->create();
    $user->tenants()->attach($own);
    $user->assignRole(Role::Admin->value);

    // Filament answers 404 rather than 403 here on purpose: a stranger must not
    // be able to learn that t2 exists by reading the status code.
    $this->actingAs($user)
        ->get('http://t2.tenant-app.test/dashboard')
        ->assertNotFound();
});

it('lets a super admin support any tenant panel', function (): void {
    Tenant::factory()->create(['slug' => 't1']);

    $user = User::factory()->superAdmin()->create();

    $this->actingAs($user)
        ->get('http://t1.tenant-app.test/dashboard')
        ->assertOk();
});

it('redirects a guest on a tenant panel to that tenant login', function (): void {
    Tenant::factory()->create(['slug' => 't1']);

    $this->get('http://t1.tenant-app.test/dashboard')
        ->assertRedirect('http://t1.tenant-app.test/dashboard/login');
});

/*
|--------------------------------------------------------------------------
| Branding
|--------------------------------------------------------------------------
|
| A tenant's own name replaces the generic panel name once someone is
| signed in and a tenant is known — see TenantPanelProvider::brandName(). The
| tenant menu is off (.ai/rules/filament.md), so there is nowhere for another
| tenant's name to leak into this page at all.
*/

it('shows the generic panel name before anyone signs in', function (): void {
    Tenant::factory()->create(['slug' => 't1', 'name' => 'Spice Garden']);

    $this->get('http://t1.tenant-app.test/dashboard/login')
        ->assertOk()
        ->assertSee(config('app.name'))
        ->assertDontSee('Spice Garden');
});

it("shows the tenant's own name once someone is signed in", function (): void {
    $tenant = Tenant::factory()->create(['slug' => 't1', 'name' => 'Spice Garden']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);
    $user->assignRole(Role::Admin->value);

    $this->actingAs($user)
        ->get('http://t1.tenant-app.test/dashboard')
        ->assertOk()
        ->assertSee('Spice Garden')
        ->assertDontSee(config('app.name'));
});

it('gives a super admin no tenant menu to switch tenants from', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 't1', 'name' => 'Spice Garden']);
    $other = Tenant::factory()->create(['name' => 'Other Place']);

    $user = User::factory()->superAdmin()->create();

    $this->actingAs($user)
        ->get('http://t1.tenant-app.test/dashboard')
        ->assertOk()
        ->assertSee('Spice Garden')
        ->assertDontSee('Other Place');
});
