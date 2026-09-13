<?php

use App\Enums\CountryCallingCode;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Platform\Resources\Restaurants\Pages\CreateRestaurant;
use App\Filament\Platform\Resources\Restaurants\Pages\EditRestaurant;
use App\Filament\Platform\Resources\Restaurants\Pages\ListRestaurants;
use App\Filament\Platform\Resources\Restaurants\RelationManagers\UsersRelationManager;
use App\Filament\Platform\Resources\Restaurants\RestaurantResource;
use App\Filament\Platform\Resources\Users\UserResource;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Who may manage restaurants
|--------------------------------------------------------------------------
|
| restaurant.manage is granted to no role, so the roster of restaurants is
| the product team's alone. These check the policy directly, so a failure names
| the rule rather than a status code.
|
*/

it('lets the product team manage restaurants', function (): void {
    $user = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create();

    expect($user->can('viewAny', Restaurant::class))->toBeTrue()
        ->and($user->can('create', Restaurant::class))->toBeTrue()
        ->and($user->can('update', $restaurant))->toBeTrue()
        ->and($user->can('delete', $restaurant))->toBeTrue();
});

it('refuses restaurant management to every restaurant role', function (Role $role): void {
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $restaurant = Restaurant::factory()->create();

    expect($user->can('viewAny', Restaurant::class))->toBeFalse()
        ->and($user->can('create', Restaurant::class))->toBeFalse()
        ->and($user->can('update', $restaurant))->toBeFalse()
        ->and($user->can('delete', $restaurant))->toBeFalse();
})->with(Role::cases());

it('keeps a restaurant admin out of the restaurants page', function (): void {
    $restaurant = Restaurant::factory()->create();
    $user = User::factory()->create();
    $user->restaurants()->attach($restaurant);
    $user->assignRole(Role::Admin->value);

    $this->actingAs($user)
        ->get('http://restaurant-app.test/dashboard/restaurants')
        ->assertForbidden();
});

it('serves the restaurants page to the product team', function (): void {
    $user = User::factory()->superAdmin()->create();

    $this->actingAs($user)
        ->get('http://restaurant-app.test/dashboard/restaurants')
        ->assertOk();
});

it('hides the restaurants module from anyone without the permission', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::Admin->value);

    $this->actingAs($user);

    expect(RestaurantResource::canViewAny())->toBeFalse()
        ->and($user->can(Permission::RestaurantManage->value))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Creating
|--------------------------------------------------------------------------
*/

it('creates a restaurant with its address and contact details', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateRestaurant::class)
        ->fillForm([
            'name' => 'Spice Garden',
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

    $restaurant = Restaurant::query()->where('slug', 'spice')->sole();

    expect($restaurant->name)->toBe('Spice Garden')
        ->and($restaurant->address)->toBe('12 Mount Road, Chennai')
        ->and($restaurant->pincode)->toBe('600002')
        ->and($restaurant->email)->toBe('owner@spice.example.com')
        ->and($restaurant->phone_country_code)->toBe(CountryCallingCode::India)
        ->and($restaurant->phone)->toBe('9876543210')
        ->and($restaurant->secondary_phone_country_code)->toBe(CountryCallingCode::India)
        ->and($restaurant->secondary_phone)->toBe('9876543211')
        ->and($restaurant->is_active)->toBeTrue();
});

it('creates a restaurant without the optional contact details', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateRestaurant::class)
        ->fillForm([
            'name' => 'Corner Cafe',
            'slug' => 'corner',
            'address' => '3 Beach Road, Chennai',
            'pincode' => '600001',
            'phone' => '9000000000',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $restaurant = Restaurant::query()->where('slug', 'corner')->sole();

    expect($restaurant->email)->toBeNull()
        ->and($restaurant->secondary_phone)->toBeNull()
        // A country code with no number behind it is not kept.
        ->and($restaurant->secondary_phone_country_code)->toBeNull()
        ->and($restaurant->phone_country_code)->toBe(CountryCallingCode::India);
});

it('suggests a subdomain from the name', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateRestaurant::class)
        ->fillForm(['name' => 'Spice Garden'])
        ->assertFormSet(['slug' => 'spice-garden']);
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('requires everything a restaurant cannot trade without', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateRestaurant::class)
        ->fillForm([
            'name' => null,
            'slug' => null,
            'address' => null,
            'pincode' => null,
            'phone' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'slug' => 'required',
            'address' => 'required',
            'pincode' => 'required',
            'phone' => 'required',
        ]);

    expect(Restaurant::query()->count())->toBe(0);
});

it('refuses a subdomain that is not a valid DNS label', function (string $slug): void {
    enterProductTeamPanel();

    Livewire::test(CreateRestaurant::class)
        ->fillForm([
            'name' => 'Spice Garden',
            'slug' => $slug,
            'address' => '12 Mount Road',
            'pincode' => '600002',
            'phone' => '9876543210',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(Restaurant::query()->count())->toBe(0);
})->with([
    'uppercase' => 'Spice',
    'underscored' => 'spice_garden',
    'leading hyphen' => '-spice',
    'trailing hyphen' => 'spice-',
    'dotted' => 'spice.garden',
]);

it('refuses a subdomain another restaurant already serves', function (): void {
    Restaurant::factory()->create(['slug' => 'spice']);
    enterProductTeamPanel();

    Livewire::test(CreateRestaurant::class)
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

    Livewire::test(CreateRestaurant::class)
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

    Livewire::test(CreateRestaurant::class)
        ->fillForm([
            'name' => 'Spice Garden',
            'slug' => 'spice',
            'address' => '12 Mount Road',
            'pincode' => '600002',
            'phone' => $phone,
        ])
        ->call('create')
        ->assertHasFormErrors(['phone']);

    expect(Restaurant::query()->count())->toBe(0);
})->with([
    'too short' => '987654321',
    'too long' => '98765432101',
    'with the country code' => '919876543210',
    'punctuated' => '98765 43210',
    'not digits' => 'nine one two',
]);

it('requires a country code for a secondary number', function (): void {
    enterProductTeamPanel();

    Livewire::test(CreateRestaurant::class)
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
    $restaurant = Restaurant::factory()->create([
        'phone_country_code' => CountryCallingCode::India,
        'phone' => '9876543210',
        'secondary_phone_country_code' => null,
        'secondary_phone' => null,
    ]);

    expect($restaurant->dialablePhone())->toBe('+91 9876543210')
        ->and($restaurant->dialableSecondaryPhone())->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Reading, updating and deleting
|--------------------------------------------------------------------------
*/

it('lists every restaurant on the platform', function (): void {
    $restaurants = Restaurant::factory()->count(3)->create();
    enterProductTeamPanel();

    Livewire::test(ListRestaurants::class)
        ->assertCanSeeTableRecords($restaurants);
});

it("links straight to a restaurant's own admin sign-in, now that the tenant menu is off", function (): void {
    $restaurant = Restaurant::factory()->create(['slug' => 't1']);
    enterProductTeamPanel();

    Livewire::test(ListRestaurants::class)
        ->assertTableActionHasUrl('openPanel', $restaurant->signInUrl(), $restaurant);
});

it('leaves the link to another host as a real browser visit', function (): void {
    $restaurant = Restaurant::factory()->create(['slug' => 't1']);
    enterProductTeamPanel();

    // Both panels run ->spa(), which puts wire:navigate on links inside a
    // panel. A restaurant's own panel is on its subdomain, and a Livewire visit
    // cannot cross an origin — Filament compares the host and leaves this one
    // alone, which is why no spaUrlExceptions() is needed.
    $html = Livewire::test(ListRestaurants::class)->html();

    $link = str($html)->after($restaurant->signInUrl())->before('>')->toString();

    expect($link)->not->toContain('wire:navigate');
});

it('updates a restaurant', function (): void {
    $restaurant = Restaurant::factory()->create(['phone' => '9000000000']);
    enterProductTeamPanel();

    Livewire::test(EditRestaurant::class, ['record' => $restaurant->getRouteKey()])
        ->fillForm([
            'name' => 'Renamed',
            'phone' => '9111111111',
            'is_active' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($restaurant->refresh()->name)->toBe('Renamed')
        ->and($restaurant->phone)->toBe('9111111111')
        ->and($restaurant->is_active)->toBeFalse();
});

it('deletes a restaurant', function (): void {
    $restaurant = Restaurant::factory()->create();
    enterProductTeamPanel();

    Livewire::test(EditRestaurant::class, ['record' => $restaurant->getRouteKey()])
        ->callAction('delete');

    expect(Restaurant::query()->whereKey($restaurant->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Role limits
|--------------------------------------------------------------------------
|
| How many accounts may hold the admin and staff roles is set here, per
| restaurant — see Restaurant::roleLimit() and
| App\Actions\Restaurants\EnsureRoleFitsWithinLimit, which enforces it
| whenever a role is actually granted.
|
*/

it('sends a newly created restaurant straight on to creating its admin', function (): void {
    enterProductTeamPanel();

    // A restaurant with nobody on its roster cannot be opened by anyone, so
    // creating one leads into the account form rather than back to the list —
    // with the restaurant and the role it needs already chosen.
    Livewire::test(CreateRestaurant::class)
        ->fillForm([
            'name' => 'Corner Cafe',
            'slug' => 'corner',
            'address' => '3 Beach Road, Chennai',
            'pincode' => '600001',
            'phone' => '9000000000',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(UserResource::getUrl('create', [
            'tenant_id' => Restaurant::query()->where('slug', 'corner')->value('id'),
            'role' => Role::Admin->value,
        ]));
});

it('serves the restaurant edit page with its roster attached', function (): void {
    $restaurant = Restaurant::factory()->create(['slug' => 'spice']);
    User::factory()->ofRestaurant($restaurant)->create();

    // The roster itself is a lazily loaded Livewire component, so its rows are
    // not in this response — what this pins is that registering it has not
    // broken the page it hangs under. Its contents are covered below.
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(RestaurantResource::getUrl('edit', ['record' => $restaurant]))
        ->assertOk();

    expect(RestaurantResource::getRelations())->toContain(UsersRelationManager::class);
});

it('lists the roster under the restaurant\'s own record', function (): void {
    $restaurant = Restaurant::factory()->create();
    $other = Restaurant::factory()->create();

    $onTheRoster = User::factory()->ofRestaurant($restaurant)->create();
    $elsewhere = User::factory()->ofRestaurant($other)->create();

    enterProductTeamPanel();

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $restaurant,
        'pageClass' => EditRestaurant::class,
    ])
        ->assertCanSeeTableRecords([$onTheRoster])
        ->assertCanNotSeeTableRecords([$elsewhere]);
});

it('sets a restaurant\'s admin and staff limits', function (): void {
    $restaurant = Restaurant::factory()->create();
    enterProductTeamPanel();

    Livewire::test(EditRestaurant::class, ['record' => $restaurant->getRouteKey()])
        ->fillForm(['max_admins' => 2, 'max_staff' => 10])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($restaurant->refresh()->max_admins)->toBe(2)
        ->and($restaurant->max_staff)->toBe(10);
});

it('refuses to lower a limit below the roster it would already break', function (): void {
    $restaurant = Restaurant::factory()->create(['max_staff' => 5]);

    User::factory()->count(3)->create()->each(function (User $member) use ($restaurant): void {
        $member->restaurants()->attach($restaurant);
        $member->assignRole(Role::Staff->value);
    });

    enterProductTeamPanel();

    Livewire::test(EditRestaurant::class, ['record' => $restaurant->getRouteKey()])
        ->fillForm(['max_staff' => 2])
        ->call('save')
        ->assertHasFormErrors(['max_staff']);

    expect($restaurant->refresh()->max_staff)->toBe(5);
});

it('allows lowering a limit down to exactly the current roster', function (): void {
    $restaurant = Restaurant::factory()->create(['max_staff' => 5]);

    User::factory()->count(3)->create()->each(function (User $member) use ($restaurant): void {
        $member->restaurants()->attach($restaurant);
        $member->assignRole(Role::Staff->value);
    });

    enterProductTeamPanel();

    Livewire::test(EditRestaurant::class, ['record' => $restaurant->getRouteKey()])
        ->fillForm(['max_staff' => 3])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($restaurant->refresh()->max_staff)->toBe(3);
});
