<?php

use App\Enums\Locale;
use App\Enums\LocationKind;
use App\Enums\Role as RoleEnum;
use App\Enums\TenantType;
use App\Filament\Tenant\Resources\Locations\Pages\ListLocations;
use App\Models\Location;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Locations
|--------------------------------------------------------------------------
|
| Where an order goes. One generic module rather than separate Rooms and
| Tables: a hotel wants rooms, a restaurant wants tables, and both want a few
| delivery points like a pool or an entrance. A flat list, deliberately.
|
*/

/**
 * Find a location by the English half of its translated name.
 */
function locationNamed(string $name): Location
{
    return Location::query()
        ->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, $name)
        ->sole();
}

it('creates a location on the tenant in the panel', function (): void {
    $tenant = Tenant::factory()->create(['type' => TenantType::Hotel]);
    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListLocations::class)
        ->callAction('create', [
            'kind' => LocationKind::Room->value,
            'name' => [Locale::English->value => 'Room 204'],
            'code' => '204',
            'capacity' => 2,
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $location = locationNamed('Room 204');

    expect($location->tenant_id)->toBe($tenant->getKey())
        ->and($location->kind)->toBe(LocationKind::Room)
        ->and($location->code)->toBe('204')
        ->and($location->capacity)->toBe(2);
});

it('offers a hotel rooms and never tables, and a restaurant the other way about', function (): void {
    $hotel = Tenant::factory()->create(['type' => TenantType::Hotel]);
    $restaurant = Tenant::factory()->create(['type' => TenantType::Restaurant]);

    // The kinds a tenant is offered follow its type, so a hotel is never
    // invited to file something as a table. Both still get Area: a pool, an
    // entrance and a waiting room make sense whatever the business is.
    expect($hotel->type->locationKinds())->toBe([LocationKind::Room, LocationKind::Area])
        ->and($restaurant->type->locationKinds())->toBe([LocationKind::Table, LocationKind::Area])
        ->and($hotel->type->defaultLocationKind())->toBe(LocationKind::Room)
        ->and($restaurant->type->defaultLocationKind())->toBe(LocationKind::Table);

    enterTenantPanel($hotel, RoleEnum::Owner);

    $options = $hotel->type->locationKindOptions();

    expect($options)->toHaveKey(LocationKind::Room->value)
        ->and($options)->not->toHaveKey(LocationKind::Table->value);
});

it('keeps an existing location on its own kind when its tenant type changes', function (): void {
    $tenant = Tenant::factory()->create(['type' => TenantType::Hotel]);
    $room = Location::factory()->ofTenant($tenant)->room()->create();

    // A tenant that re-registers as a restaurant does not have its rooms
    // rewritten underneath it; the row keeps what it was created as.
    $tenant->update(['type' => TenantType::Restaurant]);

    expect($room->fresh()?->kind)->toBe(LocationKind::Room);
});

it('shows a hotel no Table tab, and a restaurant no Room tab', function (): void {
    $hotel = Tenant::factory()->create(['type' => TenantType::Hotel]);
    enterTenantPanel($hotel, RoleEnum::Owner);

    // The tabs follow the tenant's type too, not every case of the enum —
    // a hotel reading a permanently empty "Table 0" tab is noise.
    $tabs = array_keys(Livewire::test(ListLocations::class)->instance()->getTabs());

    expect($tabs)->toBe(['all', LocationKind::Room->value, LocationKind::Area->value]);
});

it('creates a whole range of locations at once', function (): void {
    $tenant = Tenant::factory()->create(['type' => TenantType::Hotel]);
    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListLocations::class)
        ->callAction('addSeveral', [
            'kind' => LocationKind::Room->value,
            'name_prefix' => 'Room',
            'from' => 101,
            'to' => 105,
        ])
        ->assertHasNoActionErrors();

    // A hotel does not add two hundred rooms one at a time.
    expect(Location::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count())->toBe(5)
        ->and(locationNamed('Room 103')->kind)->toBe(LocationKind::Room);
});

it('sees only its own tenant\'s locations', function (): void {
    $tenant = Tenant::factory()->create();
    $ours = Location::factory()->ofTenant($tenant)->room()->create();
    $theirs = Location::factory()->room()->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListLocations::class)
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('drags locations into the order a guest reads them', function (): void {
    $tenant = Tenant::factory()->create();
    $first = Location::factory()->ofTenant($tenant)->room()->create(['position' => 0]);
    $second = Location::factory()->ofTenant($tenant)->room()->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // reorderTable() short-circuits on the reorder() policy method, so calling
    // it for real is the only thing that proves the drag works.
    Livewire::test(ListLocations::class)
        ->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($first->fresh()?->position)->toBe(2)
        ->and($second->fresh()?->position)->toBe(1);
});

it('refuses the page to an account that may not manage locations', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Guest);

    Livewire::test(ListLocations::class)->assertForbidden();
});
