<?php

use App\Actions\Restaurants\SetRestaurantUserRoles;
use App\Enums\FilamentPanel;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Restaurant\Resources\Users\Pages\CreateUser;
use App\Filament\Restaurant\Resources\Users\Pages\EditUser;
use App\Filament\Restaurant\Resources\Users\Pages\ListUsers;
use App\Models\Restaurant;
use App\Models\Role;
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
    $restaurant = Restaurant::factory()->create(['slug' => 't1']);
    $user = User::factory()->create();
    $user->restaurants()->attach($restaurant);
    $user->assignRole(RoleEnum::Staff->value);

    $this->actingAs($user)
        ->get('http://t1.restaurant-app.test/dashboard/users')
        ->assertForbidden();
});

it('serves the users page to a restaurant admin', function (): void {
    $restaurant = Restaurant::factory()->create(['slug' => 't1']);
    $user = User::factory()->create();
    $user->restaurants()->attach($restaurant);
    $user->assignRole(RoleEnum::Admin->value);

    $this->actingAs($user)
        ->get('http://t1.restaurant-app.test/dashboard/users')
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| One restaurant never sees another's roster
|--------------------------------------------------------------------------
*/

it('lists only the people who staff this restaurant', function (): void {
    $restaurant = Restaurant::factory()->create();
    $other = Restaurant::factory()->create();

    // Created before the panel is entered: once it is, Filament's tenancy
    // observer puts anyone created into the restaurant being served.
    $colleague = User::factory()->create();
    $colleague->restaurants()->attach($restaurant);

    $stranger = User::factory()->create();
    $stranger->restaurants()->attach($other);

    $admin = enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$admin, $colleague])
        ->assertCanNotSeeTableRecords([$stranger]);
});

it('answers not found for a user of another restaurant', function (): void {
    $own = Restaurant::factory()->create(['slug' => 't1']);
    $other = Restaurant::factory()->create(['slug' => 't2']);

    $admin = User::factory()->create();
    $admin->restaurants()->attach($own);
    $admin->assignRole(RoleEnum::Admin->value);

    $stranger = User::factory()->create();
    $stranger->restaurants()->attach($other);

    // Not 403: a restaurant must not learn that an account exists elsewhere.
    $this->actingAs($admin)
        ->get("http://t1.restaurant-app.test/dashboard/users/{$stranger->getKey()}/edit")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Adding someone
|--------------------------------------------------------------------------
*/

it('creates an account and puts it on this restaurant roster', function (): void {
    $restaurant = Restaurant::factory()->create();
    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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
        ->and($created->restaurants->pluck('id')->all())->toBe([$restaurant->getKey()])
        ->and($created->hasRole(RoleEnum::Staff->value))->toBeTrue()
        ->and($created->isSuperAdmin())->toBeFalse();
});

it('joins an existing account to the restaurant rather than duplicating it', function (): void {
    $restaurant = Restaurant::factory()->create();
    $other = Restaurant::factory()->create();

    $existing = User::factory()->create(['email' => 'chef@example.com', 'name' => 'Chef']);
    $existing->restaurants()->attach($other);
    $existing->assignRole(RoleEnum::Admin->value);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Chef',
            'email' => 'chef@example.com',
            'roles' => [RoleEnum::Staff->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->withEmail('chef@example.com')->count())->toBe(1)
        ->and($existing->refresh()->restaurants()->count())->toBe(2)
        // Roles are held per account, so joining a second restaurant must not
        // rewrite what this person may do at the first.
        ->and($existing->hasRole(RoleEnum::Admin->value))->toBeTrue()
        ->and($existing->hasRole(RoleEnum::Staff->value))->toBeFalse();
});

it('finds an existing account however the address was capitalised', function (): void {
    $restaurant = Restaurant::factory()->create();
    $existing = User::factory()->create(['email' => 'chef@example.com']);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Chef', 'email' => 'CHEF@example.com'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->withEmail('chef@example.com')->count())->toBe(1)
        ->and($existing->refresh()->restaurants()->whereKey($restaurant)->exists())->toBeTrue();
});

it('requires a name and a real email address', function (array $data, array $errors): void {
    $restaurant = Restaurant::factory()->create();
    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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

it('offers only the roles a restaurant may hand out', function (): void {
    $restaurant = Restaurant::factory()->create();
    $productTeamRole = Role::factory()->create();
    $productTeamRole->givePermissionTo(PermissionEnum::RestaurantManage->value);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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
    $restaurant = Restaurant::factory()->create();
    $productTeamRole = Role::factory()->create();
    $productTeamRole->givePermissionTo(PermissionEnum::RestaurantManage->value);

    $member = User::factory()->create();
    $member->restaurants()->attach($restaurant);

    app(SetRestaurantUserRoles::class)($member, [$productTeamRole->name, RoleEnum::Staff->value]);

    expect($member->refresh()->hasRole($productTeamRole->name))->toBeFalse()
        ->and($member->hasRole(RoleEnum::Staff->value))->toBeTrue()
        ->and($member->can(PermissionEnum::RestaurantManage->value))->toBeFalse();
});

it('changes the roles of someone who staffs only this restaurant', function (): void {
    // Two admins on purpose: enterRestaurantPanel() seats one to work the
    // panel from, and this test promotes a second — a restaurant with the
    // default limit of one would refuse that promotion for a reason this
    // test is not about. See UserManagementTest's own limit coverage.
    $restaurant = Restaurant::factory()->create(['max_admins' => 2]);

    $member = User::factory()->create();
    $member->restaurants()->attach($restaurant);
    $member->assignRole(RoleEnum::Staff->value);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(EditUser::class, ['record' => $member->getKey()])
        ->assertFormSet(['roles' => [RoleEnum::Staff->value]])
        ->fillForm(['roles' => [RoleEnum::Admin->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($member->refresh()->getRoleNames()->all())->toBe([RoleEnum::Admin->value]);
});

it('leaves the roles of someone who staffs two restaurants alone', function (): void {
    $restaurant = Restaurant::factory()->create();
    $other = Restaurant::factory()->create();

    $member = User::factory()->create();
    $member->restaurants()->attach([$restaurant->getKey(), $other->getKey()]);
    $member->assignRole(RoleEnum::Staff->value);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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
| A restaurant may hold only so many admins and staff at once — see
| Restaurant::roleLimit() and App\Actions\Restaurants\EnsureRoleFitsWithinLimit.
|
*/

it('refuses a second admin once the restaurant already has one', function (): void {
    // The default limit: enterRestaurantPanel() seats the one admin this
    // restaurant is allowed.
    $restaurant = Restaurant::factory()->create();
    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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

it('refuses staff past the restaurant\'s own limit', function (): void {
    // Created before the panel is entered: once it is, Filament's tenancy
    // observer puts anyone created into the restaurant being served, and
    // attaching it again here would collide with that.
    $restaurant = Restaurant::factory()->create(['max_staff' => 1]);
    $existing = User::factory()->create();
    $existing->restaurants()->attach($restaurant);
    $existing->assignRole(RoleEnum::Staff->value);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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
    $restaurant = Restaurant::factory()->create(['max_staff' => 1]);
    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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
    $restaurant = Restaurant::factory()->create();
    $admin = enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // The restaurant already has its one allowed admin — the person entering
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

it('removes someone from the restaurant without deleting their account', function (): void {
    $restaurant = Restaurant::factory()->create();
    $other = Restaurant::factory()->create();

    $member = User::factory()->create();
    $member->restaurants()->attach([$restaurant->getKey(), $other->getKey()]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('removeFromRestaurant', $member);

    // Looking past the tenant scope on purpose: the point of this test is that
    // the account survives outside the restaurant it was removed from.
    $stillExists = User::query()
        ->withoutGlobalScope(Filament::getTenancyScopeName())
        ->whereKey($member->getKey())
        ->exists();

    expect($stillExists)->toBeTrue()
        ->and($member->refresh()->restaurants->pluck('id')->all())->toBe([$other->getKey()]);
});

it('leaves someone removed from their last restaurant with no panel to enter', function (): void {
    $restaurant = Restaurant::factory()->create();

    $member = User::factory()->create();
    $member->restaurants()->attach($restaurant);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('removeFromRestaurant', $member);

    expect($member->refresh()->canAccessPanel(filament()->getPanel(FilamentPanel::Restaurant->value)))->toBeFalse();
});

it('never lets someone remove themselves', function (): void {
    $restaurant = Restaurant::factory()->create();
    $admin = enterRestaurantPanel($restaurant, RoleEnum::Admin);

    expect($admin->can('removeFromRestaurant', $admin))->toBeFalse();

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('removeFromRestaurant', $admin);

    expect($admin->refresh()->restaurants()->whereKey($restaurant)->exists())->toBeTrue();
});
