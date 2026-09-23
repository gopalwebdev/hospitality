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
| Tables: a hotel wants rooms, a restaurant wants tables, both want a few
| delivery points like a pool, and a zone groups them a floor at a time.
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
        ->and($location->capacity)->toBe(2)
        ->and($location->parent_id)->toBeNull();
});

it('offers a hotel rooms and never tables, and a restaurant the other way about', function (): void {
    $hotel = Tenant::factory()->create(['type' => TenantType::Hotel]);
    $restaurant = Tenant::factory()->create(['type' => TenantType::Restaurant]);

    // The kinds a tenant is offered follow its type, so a hotel is never
    // invited to file something as a table. Every tenant still gets Area and
    // Zone, because a pool and a floor make sense for both.
    expect($hotel->type->locationKinds())->toBe([LocationKind::Room, LocationKind::Area, LocationKind::Zone])
        ->and($restaurant->type->locationKinds())->toBe([LocationKind::Table, LocationKind::Area, LocationKind::Zone])
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

it('nests a location under a zone and refuses a third level', function (): void {
    $tenant = Tenant::factory()->create();
    $zone = Location::factory()->ofTenant($tenant)->zone()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->withParent($zone)->create();

    expect($room->parent_id)->toBe($zone->getKey())
        ->and($zone->children()->pluck('id')->all())->toBe([$room->getKey()]);

    // Two levels is the whole of the depth, exactly as a menu's categories are.
    expect(fn () => Location::factory()->ofTenant($tenant)->room()->create(['parent_id' => $room->getKey()]))
        ->toThrow(LogicException::class);
});

it('refuses a parent that is not a zone, a zone with a parent, and a row as its own parent', function (): void {
    $tenant = Tenant::factory()->create();
    $zone = Location::factory()->ofTenant($tenant)->zone()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create();

    // Only a zone groups others: a room under a room says nothing useful.
    expect(fn () => Location::factory()->ofTenant($tenant)->room()->create(['parent_id' => $room->getKey()]))
        ->toThrow(LogicException::class);

    // A zone is the top level by definition.
    $second = Location::factory()->ofTenant($tenant)->zone()->create();

    expect(fn () => $second->update(['parent_id' => $zone->getKey()]))
        ->toThrow(LogicException::class);

    expect(fn () => $room->update(['parent_id' => $room->getKey()]))
        ->toThrow(LogicException::class);
});

it('refuses a parent belonging to another tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $otherZone = Location::factory()->zone()->create();

    // The schema has no composite key to say this, so InheritParentTenant does.
    expect(fn () => Location::factory()->ofTenant($tenant)->room()->create(['parent_id' => $otherZone->getKey()]))
        ->toThrow(LogicException::class);
});

it('leaves a zone out of the places an order may be sent to', function (): void {
    $tenant = Tenant::factory()->create();
    $zone = Location::factory()->ofTenant($tenant)->zone()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->withParent($zone)->create();
    $area = Location::factory()->ofTenant($tenant)->area()->create();

    $deliverable = Location::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->deliverable()
        ->pluck('id')
        ->all();

    // Nothing is ever delivered "to Floor 2".
    expect($deliverable)->toContain($room->getKey())
        ->and($deliverable)->toContain($area->getKey())
        ->and($deliverable)->not->toContain($zone->getKey())
        ->and(LocationKind::Zone->isDeliverable())->toBeFalse()
        ->and(LocationKind::Zone->canHoldChildren())->toBeTrue()
        ->and(LocationKind::Room->canHoldChildren())->toBeFalse();
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
