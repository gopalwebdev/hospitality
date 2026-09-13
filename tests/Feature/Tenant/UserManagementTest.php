<?php

use App\Actions\Tenants\SetTenantUserRoles;
use App\Enums\FilamentPanel;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\Users\Pages\CreateUser;
use App\Filament\Tenant\Resources\Users\Pages\EditUser;
use App\Filament\Tenant\Resources\Users\Pages\ListUsers;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Who may manage a roster
|--------------------------------------------------------------------------
|
| user.manage is what opens this module, and Admin is the only role that holds
| it. The policy is checked directly so a failure names the rule.
|
*/

it('lets the roles holding user.manage manage the roster', function (RoleEnum $roleEnum): void {
    $user = User::factory()->create();
    $user->assignRole($roleEnum->value);

    expect($user->can('viewAny', User::class))->toBeTrue()
        ->and($user->can('create', User::class))->toBeTrue()
        ->and($user->can('update', $user))->toBeTrue();
})->with([RoleEnum::Admin]);

it('refuses the roster to roles without user.manage', function (RoleEnum $roleEnum): void {
    $user = User::factory()->create();
    $user->assignRole($roleEnum->value);

    expect($user->can(PermissionEnum::UserManage->value))->toBeFalse()
        ->and($user->can('viewAny', User::class))->toBeFalse()
        ->and($user->can('create', User::class))->toBeFalse();
})->with([RoleEnum::Staff, RoleEnum::Guest]);

it('keeps staff off the users page', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 't1']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);
    $user->assignRole(RoleEnum::Staff->value);

    $this->actingAs($user)
        ->get('http://t1.tenant-app.test/dashboard/users')
        ->assertForbidden();
});

it('serves the users page to a tenant admin', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 't1']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);
    $user->assignRole(RoleEnum::Admin->value);

    $this->actingAs($user)
        ->get('http://t1.tenant-app.test/dashboard/users')
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| One tenant never sees another's roster
|--------------------------------------------------------------------------
*/

it('lists only the people who staff this tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    // Created before the panel is entered: once it is, Filament's tenancy
    // observer puts anyone created into the tenant being served.
    $colleague = User::factory()->create();
    $colleague->tenants()->attach($tenant);

    $stranger = User::factory()->create();
    $stranger->tenants()->attach($other);

    $admin = enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$admin, $colleague])
        ->assertCanNotSeeTableRecords([$stranger]);
});

it('answers not found for a user of another tenant', function (): void {
    $own = Tenant::factory()->create(['slug' => 't1']);
    $other = Tenant::factory()->create(['slug' => 't2']);

    $admin = User::factory()->create();
    $admin->tenants()->attach($own);
    $admin->assignRole(RoleEnum::Admin->value);

    $stranger = User::factory()->create();
    $stranger->tenants()->attach($other);

    // Not 403: a tenant must not learn that an account exists elsewhere.
    $this->actingAs($admin)
        ->get("http://t1.tenant-app.test/dashboard/users/{$stranger->getKey()}/edit")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Adding someone
|--------------------------------------------------------------------------
*/

it('creates an account and puts it on this tenant roster', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Priya',
            'email' => 'priya@example.com',
            'roles' => [RoleEnum::Staff->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::query()->withEmail('priya@example.com')->sole();

    expect($created->name)->toBe('Priya')
        ->and($created->tenants->pluck('id')->all())->toBe([$tenant->getKey()])
        ->and($created->hasRole(RoleEnum::Staff->value))->toBeTrue()
        ->and($created->isSuperAdmin())->toBeFalse();
});

it('joins an existing account to the tenant rather than duplicating it', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    $existing = User::factory()->create(['email' => 'chef@example.com', 'name' => 'Chef']);
    $existing->tenants()->attach($other);
    $existing->assignRole(RoleEnum::Admin->value);

    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Chef',
            'email' => 'chef@example.com',
            'roles' => [RoleEnum::Staff->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->withEmail('chef@example.com')->count())->toBe(1)
        ->and($existing->refresh()->tenants()->count())->toBe(2)
        // Roles are held per account, so joining a second tenant must not
        // rewrite what this person may do at the first.
        ->and($existing->hasRole(RoleEnum::Admin->value))->toBeTrue()
        ->and($existing->hasRole(RoleEnum::Staff->value))->toBeFalse();
});

it('finds an existing account however the address was capitalised', function (): void {
    $tenant = Tenant::factory()->create();
    $existing = User::factory()->create(['email' => 'chef@example.com']);

    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Chef', 'email' => 'CHEF@example.com'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->withEmail('chef@example.com')->count())->toBe(1)
        ->and($existing->refresh()->tenants()->whereKey($tenant)->exists())->toBeTrue();
});

it('requires a name and a real email address', function (array $data, array $errors): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(CreateUser::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasFormErrors($errors);
})->with([
    'nothing at all' => [
        ['name' => null, 'email' => null],
        ['name' => 'required', 'email' => 'required'],
    ],
    'not an address' => [
        ['name' => 'Priya', 'email' => 'priya'],
        ['email' => 'email'],
    ],
]);

/*
|--------------------------------------------------------------------------
| Assigning roles
|--------------------------------------------------------------------------
*/

it('offers only the roles a tenant may hand out', function (): void {
    $tenant = Tenant::factory()->create();
    $productTeamRole = Role::factory()->create();
    $productTeamRole->givePermissionTo(PermissionEnum::TenantManage->value);

    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(CreateUser::class)
        ->assertFormFieldExists('roles', function (CheckboxList $field) use ($productTeamRole): bool {
            $offered = array_keys($field->getOptions());

            expect($offered)->toContain(RoleEnum::Staff->value)
                ->and($offered)->toContain(RoleEnum::Admin->value)
                ->and($offered)->not->toContain($productTeamRole->name);

            return true;
        });
});

it('refuses a product team role even when one is submitted anyway', function (): void {
    $tenant = Tenant::factory()->create();
    $productTeamRole = Role::factory()->create();
    $productTeamRole->givePermissionTo(PermissionEnum::TenantManage->value);

    $member = User::factory()->create();
    $member->tenants()->attach($tenant);

    app(SetTenantUserRoles::class)($member, [$productTeamRole->name, RoleEnum::Staff->value]);

    expect($member->refresh()->hasRole($productTeamRole->name))->toBeFalse()
        ->and($member->hasRole(RoleEnum::Staff->value))->toBeTrue()
        ->and($member->can(PermissionEnum::TenantManage->value))->toBeFalse();
});

it('changes the roles of someone who staffs only this tenant', function (): void {
    // Two admins on purpose: enterTenantPanel() seats one to work the
    // panel from, and this test promotes a second — a tenant with the
    // default limit of one would refuse that promotion for a reason this
    // test is not about. See UserManagementTest's own limit coverage.
    $tenant = Tenant::factory()->create(['max_admins' => 2]);

    $member = User::factory()->create();
    $member->tenants()->attach($tenant);
    $member->assignRole(RoleEnum::Staff->value);

    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(EditUser::class, ['record' => $member->getKey()])
        ->assertFormSet(['roles' => [RoleEnum::Staff->value]])
        ->fillForm(['roles' => [RoleEnum::Admin->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($member->refresh()->getRoleNames()->all())->toBe([RoleEnum::Admin->value]);
});

it('leaves the roles of someone who staffs two tenants alone', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    $member = User::factory()->create();
    $member->tenants()->attach([$tenant->getKey(), $other->getKey()]);
    $member->assignRole(RoleEnum::Staff->value);

    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(EditUser::class, ['record' => $member->getKey()])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($member->refresh()->name)->toBe('Renamed')
        ->and($member->getRoleNames()->all())->toBe([RoleEnum::Staff->value]);
});

/*
|--------------------------------------------------------------------------
| Role limits
|--------------------------------------------------------------------------
|
| A tenant may hold only so many admins and staff at once — see
| Tenant::roleLimit() and App\Actions\Tenants\EnsureRoleFitsWithinLimit.
|
*/

it('refuses a second admin once the tenant already has one', function (): void {
    // The default limit: enterTenantPanel() seats the one admin this
    // tenant is allowed.
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Second Admin',
            'email' => 'second-admin@example.com',
            'roles' => [RoleEnum::Admin->value],
        ])
        ->call('create')
        ->assertHasFormErrors(['roles']);

    expect(User::query()->withEmail('second-admin@example.com')->exists())->toBeFalse();
});

it('refuses staff past the tenant\'s own limit', function (): void {
    // Created before the panel is entered: once it is, Filament's tenancy
    // observer puts anyone created into the tenant being served, and
    // attaching it again here would collide with that.
    $tenant = Tenant::factory()->create(['max_staff' => 1]);
    $existing = User::factory()->create();
    $existing->tenants()->attach($tenant);
    $existing->assignRole(RoleEnum::Staff->value);

    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Second Staff',
            'email' => 'second-staff@example.com',
            'roles' => [RoleEnum::Staff->value],
        ])
        ->call('create')
        ->assertHasFormErrors(['roles']);

    expect(User::query()->withEmail('second-staff@example.com')->exists())->toBeFalse();
});

it('still allows the last staff slot the limit permits', function (): void {
    $tenant = Tenant::factory()->create(['max_staff' => 1]);
    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Only Staff',
            'email' => 'only-staff@example.com',
            'roles' => [RoleEnum::Staff->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->withEmail('only-staff@example.com')->exists())->toBeTrue();
});

it('does not count someone against their own limit while re-saving their roles', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = enterTenantPanel($tenant, RoleEnum::Admin);

    // The tenant already has its one allowed admin — the person entering
    // the panel — so re-saving that same admin's own roles must not be
    // refused as though it were a second one.
    Livewire::test(EditUser::class, ['record' => $admin->getKey()])
        ->fillForm(['roles' => [RoleEnum::Admin->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($admin->refresh()->hasRole(RoleEnum::Admin->value))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Taking someone off the roster
|--------------------------------------------------------------------------
*/

it('removes someone from the tenant without deleting their account', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    $member = User::factory()->create();
    $member->tenants()->attach([$tenant->getKey(), $other->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('removeFromTenant', $member);

    // Looking past the tenant scope on purpose: the point of this test is that
    // the account survives outside the tenant it was removed from.
    $stillExists = User::query()
        ->withoutGlobalScope(Filament::getTenancyScopeName())
        ->whereKey($member->getKey())
        ->exists();

    expect($stillExists)->toBeTrue()
        ->and($member->refresh()->tenants->pluck('id')->all())->toBe([$other->getKey()]);
});

it('leaves someone removed from their last tenant with no panel to enter', function (): void {
    $tenant = Tenant::factory()->create();

    $member = User::factory()->create();
    $member->tenants()->attach($tenant);

    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('removeFromTenant', $member);

    expect($member->refresh()->canAccessPanel(filament()->getPanel(FilamentPanel::Tenant->value)))->toBeFalse();
});

it('never lets someone remove themselves', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = enterTenantPanel($tenant, RoleEnum::Admin);

    expect($admin->can('removeFromTenant', $admin))->toBeFalse();

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('removeFromTenant', $admin);

    expect($admin->refresh()->tenants()->whereKey($tenant)->exists())->toBeTrue();
});
