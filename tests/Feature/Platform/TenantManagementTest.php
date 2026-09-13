<?php

use App\Enums\CountryCallingCode;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TenantType;
use App\Filament\Platform\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Platform\Resources\Tenants\Pages\EditTenant;
use App\Filament\Platform\Resources\Tenants\Pages\ListTenants;
use App\Filament\Platform\Resources\Tenants\RelationManagers\UsersRelationManager;
use App\Filament\Platform\Resources\Tenants\TenantResource;
use App\Filament\Platform\Resources\Users\UserResource;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Who may manage tenants
|--------------------------------------------------------------------------
|
| tenant.manage is granted to no role, so the roster of tenants is
| the product team's alone. These check the policy directly, so a failure names
| the rule rather than a status code.
|
*/

it('lets the product team manage tenants', function (): void {
    $user = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create();

    expect($user->can('viewAny', Tenant::class))->toBeTrue()
        ->and($user->can('create', Tenant::class))->toBeTrue()
        ->and($user->can('update', $tenant))->toBeTrue()
        ->and($user->can('delete', $tenant))->toBeTrue();
});

it('refuses tenant management to every tenant role', function (Role $role): void {
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $tenant = Tenant::factory()->create();

    expect($user->can('viewAny', Tenant::class))->toBeFalse()
        ->and($user->can('create', Tenant::class))->toBeFalse()
        ->and($user->can('update', $tenant))->toBeFalse()
        ->and($user->can('delete', $tenant))->toBeFalse();
})->with(Role::cases());

it('keeps a tenant admin out of the tenants page', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);
    $user->assignRole(Role::Admin->value);

    $this->actingAs($user)
        ->get('http://restaurant-app.test/dashboard/tenants')
        ->assertForbidden();
});

it('serves the tenants page to the product team', function (): void {
    $user = User::factory()->superAdmin()->create();

    $this->actingAs($user)
        ->get('http://restaurant-app.test/dashboard/tenants')
        ->assertOk();
});

it('hides the tenants module from anyone without the permission', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::Admin->value);

    $this->actingAs($user);

    expect(TenantResource::canViewAny())->toBeFalse()
        ->and($user->can(Permission::TenantManage->value))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Creating
|--------------------------------------------------------------------------
*/

it('creates a tenant with its address and contact details', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Spice Garden',
            'type' => TenantType::Restaurant->value,
            'slug' => 'spice',
            'address' => '12 Mount Road, Chennai',
            'pincode' => '600002',
            'email' => 'owner@spice.example.com',
            'phone_country_code' => CountryCallingCode::India->value,
            'phone' => '9876543210',
            'secondary_phone_country_code' => CountryCallingCode::India->value,
            'secondary_phone' => '9876543211',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = Tenant::query()->where('slug', 'spice')->sole();

    expect($tenant->name)->toBe('Spice Garden')
        ->and($tenant->type)->toBe(TenantType::Restaurant)
        ->and($tenant->address)->toBe('12 Mount Road, Chennai')
        ->and($tenant->pincode)->toBe('600002')
        ->and($tenant->email)->toBe('owner@spice.example.com')
        ->and($tenant->phone_country_code)->toBe(CountryCallingCode::India)
        ->and($tenant->phone)->toBe('9876543210')
        ->and($tenant->secondary_phone_country_code)->toBe(CountryCallingCode::India)
        ->and($tenant->secondary_phone)->toBe('9876543211')
        ->and($tenant->is_active)->toBeTrue();
});

it('creates a tenant without the optional contact details', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Harbour View',
            'type' => TenantType::Hotel->value,
            'slug' => 'harbour',
            'address' => '3 Beach Road, Chennai',
            'pincode' => '600001',
            'phone' => '9000000000',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = Tenant::query()->where('slug', 'harbour')->sole();

    expect($tenant->type)->toBe(TenantType::Hotel)
        ->and($tenant->email)->toBeNull()
        ->and($tenant->secondary_phone)->toBeNull()
        // A country code with no number behind it is not kept.
        ->and($tenant->secondary_phone_country_code)->toBeNull()
        ->and($tenant->phone_country_code)->toBe(CountryCallingCode::India);
});

it('suggests a subdomain from the name', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateTenant::class)
        ->fillForm(['name' => 'Spice Garden'])
        ->assertFormSet(['slug' => 'spice-garden']);
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('requires everything a tenant cannot trade without', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => null,
            'type' => null,
            'slug' => null,
            'address' => null,
            'pincode' => null,
            'phone' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'type' => 'required',
            'slug' => 'required',
            'address' => 'required',
            'pincode' => 'required',
            'phone' => 'required',
        ]);

    expect(Tenant::query()->count())->toBe(0);
});

it('refuses a type the platform does not serve', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Corner Cafe',
            'type' => 'cafe',
            'slug' => 'corner',
            'address' => '3 Beach Road, Chennai',
            'pincode' => '600001',
            'phone' => '9000000000',
        ])
        ->call('create')
        ->assertHasFormErrors(['type']);

    expect(Tenant::query()->count())->toBe(0);
});

it('refuses a subdomain that is not a valid DNS label', function (string $slug): void {
    enterProductTeamPanel();

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Spice Garden',
            'slug' => $slug,
            'address' => '12 Mount Road',
            'pincode' => '600002',
            'phone' => '9876543210',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(Tenant::query()->count())->toBe(0);
})->with([
    'uppercase' => 'Spice',
    'underscored' => 'spice_garden',
    'leading hyphen' => '-spice',
    'trailing hyphen' => 'spice-',
    'dotted' => 'spice.garden',
]);

it('refuses a subdomain another tenant already serves', function (): void {
    Tenant::factory()->create(['slug' => 'spice']);
    enterProductTeamPanel();

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Spice Garden Two',
            'slug' => 'spice',
            'address' => '12 Mount Road',
            'pincode' => '600002',
            'phone' => '9876543210',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);
});

it('refuses an address that is not an email address', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Spice Garden',
            'slug' => 'spice',
            'address' => '12 Mount Road',
            'pincode' => '600002',
            'phone' => '9876543210',
            'email' => 'not-an-address',
        ])
        ->call('create')
        ->assertHasFormErrors(['email' => 'email']);
});

it('refuses a mobile number that is not ten digits', function (string $phone): void {
    enterProductTeamPanel();

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Spice Garden',
            'slug' => 'spice',
            'address' => '12 Mount Road',
            'pincode' => '600002',
            'phone' => $phone,
        ])
        ->call('create')
        ->assertHasFormErrors(['phone']);

    expect(Tenant::query()->count())->toBe(0);
})->with([
    'too short' => '987654321',
    'too long' => '98765432101',
    'with the country code' => '919876543210',
    'punctuated' => '98765 43210',
    'not digits' => 'nine one two',
]);

it('requires a country code for a secondary number', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Spice Garden',
            'slug' => 'spice',
            'address' => '12 Mount Road',
            'pincode' => '600002',
            'phone' => '9876543210',
            'secondary_phone_country_code' => null,
            'secondary_phone' => '9876543211',
        ])
        ->call('create')
        ->assertHasFormErrors(['secondary_phone_country_code']);
});

it('writes a number back out with its country code', function (): void {
    $tenant = Tenant::factory()->create([
        'phone_country_code' => CountryCallingCode::India,
        'phone' => '9876543210',
        'secondary_phone_country_code' => null,
        'secondary_phone' => null,
    ]);

    expect($tenant->dialablePhone())->toBe('+91 9876543210')
        ->and($tenant->dialableSecondaryPhone())->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Reading, updating and deleting
|--------------------------------------------------------------------------
*/

it('lists every tenant on the platform', function (): void {
    $tenants = Tenant::factory()->count(3)->create();
    enterProductTeamPanel();

    Livewire::test(ListTenants::class)
        ->assertCanSeeTableRecords($tenants);
});

it('shows what kind of business each tenant is, and filters on it', function (): void {
    $hotel = Tenant::factory()->hotel()->create();
    $restaurant = Tenant::factory()->create();
    enterProductTeamPanel();

    Livewire::test(ListTenants::class)
        ->assertTableColumnFormattedStateSet('type', 'Hotel', $hotel)
        ->assertTableColumnFormattedStateSet('type', 'Restaurant', $restaurant)
        ->filterTable('type', TenantType::Hotel->value)
        ->assertCanSeeTableRecords([$hotel])
        ->assertCanNotSeeTableRecords([$restaurant]);
});

it("links straight to a tenant's own admin sign-in, now that the tenant menu is off", function (): void {
    $tenant = Tenant::factory()->create(['slug' => 't1']);
    enterProductTeamPanel();

    Livewire::test(ListTenants::class)
        ->assertTableActionHasUrl('openPanel', $tenant->signInUrl(), $tenant);
});

it('leaves the link to another host as a real browser visit', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 't1']);
    enterProductTeamPanel();

    // Both panels run ->spa(), which puts wire:navigate on links inside a
    // panel. A tenant's own panel is on its subdomain, and a Livewire visit
    // cannot cross an origin — Filament compares the host and leaves this one
    // alone, which is why no spaUrlExceptions() is needed.
    $html = Livewire::test(ListTenants::class)->html();

    $link = str($html)->after($tenant->signInUrl())->before('>')->toString();

    expect($link)->not->toContain('wire:navigate');
});

it('updates a tenant', function (): void {
    $tenant = Tenant::factory()->create(['phone' => '9000000000']);
    enterProductTeamPanel();

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm([
            'name' => 'Renamed',
            'phone' => '9111111111',
            'is_active' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->name)->toBe('Renamed')
        ->and($tenant->phone)->toBe('9111111111')
        ->and($tenant->is_active)->toBeFalse();
});

it('changes what kind of business a tenant is after it was onboarded', function (): void {
    $tenant = Tenant::factory()->create(['type' => TenantType::Restaurant]);
    enterProductTeamPanel();

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm(['type' => TenantType::Hotel->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->type)->toBe(TenantType::Hotel);
});

it('deletes a tenant', function (): void {
    $tenant = Tenant::factory()->create();
    enterProductTeamPanel();

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->callAction('delete');

    expect(Tenant::query()->whereKey($tenant->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Role limits
|--------------------------------------------------------------------------
|
| How many accounts may hold the admin and staff roles is set here, per
| tenant — see Tenant::roleLimit() and
| App\Actions\Tenants\EnsureRoleFitsWithinLimit, which enforces it
| whenever a role is actually granted.
|
*/

it('sends a newly created tenant straight on to creating its admin', function (): void {
    enterProductTeamPanel();

    // A tenant with nobody on its roster cannot be opened by anyone, so
    // creating one leads into the account form rather than back to the list —
    // with the tenant and the role it needs already chosen.
    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Corner Cafe',
            'type' => TenantType::Restaurant->value,
            'slug' => 'corner',
            'address' => '3 Beach Road, Chennai',
            'pincode' => '600001',
            'phone' => '9000000000',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(UserResource::getUrl('create', [
            'tenant_id' => Tenant::query()->where('slug', 'corner')->value('id'),
            'role' => Role::Admin->value,
        ]));
});

it('serves the tenant edit page with its roster attached', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'spice']);
    User::factory()->ofTenant($tenant)->create();

    // The roster itself is a lazily loaded Livewire component, so its rows are
    // not in this response — what this pins is that registering it has not
    // broken the page it hangs under. Its contents are covered below.
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(TenantResource::getUrl('edit', ['record' => $tenant]))
        ->assertOk();

    expect(TenantResource::getRelations())->toContain(UsersRelationManager::class);
});

it('lists the roster under the tenant\'s own record', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    $onTheRoster = User::factory()->ofTenant($tenant)->create();
    $elsewhere = User::factory()->ofTenant($other)->create();

    enterProductTeamPanel();

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => EditTenant::class,
    ])
        ->assertCanSeeTableRecords([$onTheRoster])
        ->assertCanNotSeeTableRecords([$elsewhere]);
});

it('sets a tenant\'s admin and staff limits', function (): void {
    $tenant = Tenant::factory()->create();
    enterProductTeamPanel();

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm(['max_admins' => 2, 'max_staff' => 10])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->max_admins)->toBe(2)
        ->and($tenant->max_staff)->toBe(10);
});

it('refuses to lower a limit below the roster it would already break', function (): void {
    $tenant = Tenant::factory()->create(['max_staff' => 5]);

    User::factory()->count(3)->create()->each(function (User $member) use ($tenant): void {
        $member->tenants()->attach($tenant);
        $member->assignRole(Role::Staff->value);
    });

    enterProductTeamPanel();

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm(['max_staff' => 2])
        ->call('save')
        ->assertHasFormErrors(['max_staff']);

    expect($tenant->refresh()->max_staff)->toBe(5);
});

it('allows lowering a limit down to exactly the current roster', function (): void {
    $tenant = Tenant::factory()->create(['max_staff' => 5]);

    User::factory()->count(3)->create()->each(function (User $member) use ($tenant): void {
        $member->tenants()->attach($tenant);
        $member->assignRole(Role::Staff->value);
    });

    enterProductTeamPanel();

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm(['max_staff' => 3])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->max_staff)->toBe(3);
});
