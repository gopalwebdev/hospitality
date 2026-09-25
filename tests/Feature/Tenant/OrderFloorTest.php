<?php

use App\Actions\Orders\ReadFloor;
use App\Actions\Orders\ReadLocationActivity;
use App\Enums\LocationActivity;
use App\Enums\OrderStatus;
use App\Enums\Role as RoleEnum;
use App\Enums\TenantType;
use App\Filament\Tenant\Resources\Orders\Pages\ListOrders;
use App\Models\Location;
use App\Models\Order;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Date;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| The orders page, read as the floor
|--------------------------------------------------------------------------
|
| Every room, table and delivery point at once, with what is being worked at
| each — one of the two ways ListOrders is read. **It is about work, not
| money**, on the project owner's instruction: what a card says is how many
| orders are pending, being made and ready to carry over, and what a room owes
| belongs to the list layout and to the Locations page's Settle.
|
| So these tests are mostly about what "underway" means, and about the order
| the cards come back in.
|
*/

/**
 * The orders page, switched to its floor layout.
 */
function floorOf(): Testable
{
    return Livewire::test(ListOrders::class)->call('showLayout', ListOrders::FLOOR);
}

/**
 * An order at a location, in whatever state it is being worked.
 */
function floorOrderAt(Tenant $tenant, ?Location $location, OrderStatus $status = OrderStatus::Placed): Order
{
    return Order::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'location_id' => $location?->getKey(),
        'status' => $status,
        'subtotal' => 10000,
        'tax' => 0,
        'cgst' => 0,
        'sgst' => 0,
        'charges_total' => 0,
        'total' => 10000,
    ]);
}

it('counts what is being worked at each location in one query', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create();
    $other = Location::factory()->ofTenant($tenant)->room()->create();

    floorOrderAt($tenant, $room, OrderStatus::Placed);
    floorOrderAt($tenant, $room, OrderStatus::Placed);
    floorOrderAt($tenant, $room, OrderStatus::Ready);
    floorOrderAt($tenant, $other, OrderStatus::Accepted);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $activity = app(ReadLocationActivity::class)($tenant);

    expect($queries)->toBe(1)
        ->and($activity[$room->getKey()]['pending'])->toBe(2)
        ->and($activity[$room->getKey()]['ready'])->toBe(1)
        ->and($activity[$room->getKey()]['preparing'])->toBe(0)
        ->and($activity[$room->getKey()]['orders'])->toBe(3)
        ->and($activity[$other->getKey()]['preparing'])->toBe(1);
});

it('headlines a location with the loudest thing happening there', function (): void {
    $tenant = Tenant::factory()->create();
    $ready = Location::factory()->ofTenant($tenant)->room()->create();
    $pending = Location::factory()->ofTenant($tenant)->room()->create();
    $preparing = Location::factory()->ofTenant($tenant)->room()->create();

    // Ready beats pending even with more of the latter: the food is made and
    // somebody is waiting for it to be carried over.
    floorOrderAt($tenant, $ready, OrderStatus::Ready);
    floorOrderAt($tenant, $ready, OrderStatus::Placed);
    floorOrderAt($tenant, $ready, OrderStatus::Placed);

    floorOrderAt($tenant, $pending, OrderStatus::Placed);
    floorOrderAt($tenant, $pending, OrderStatus::Accepted);

    floorOrderAt($tenant, $preparing, OrderStatus::Accepted);

    $activity = app(ReadLocationActivity::class)($tenant);

    expect($activity[$ready->getKey()]['state'])->toBe(LocationActivity::Ready)
        ->and($activity[$pending->getKey()]['state'])->toBe(LocationActivity::Pending)
        ->and($activity[$preparing->getKey()]['state'])->toBe(LocationActivity::Preparing);
});

it('leaves out an order that has been served or cancelled', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create();

    floorOrderAt($tenant, $room, OrderStatus::Served);
    floorOrderAt($tenant, $room, OrderStatus::Cancelled);

    // Served is finished work however much of it is still unpaid, and that is
    // the whole point of the floor being about work rather than money.
    expect(app(ReadLocationActivity::class)($tenant))->toBe([]);
});

it('sorts the busiest first, by how loud they are, and the quiet ones last', function (): void {
    $tenant = Tenant::factory()->create();
    $quiet = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101', 'position' => 1]);
    $preparing = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 102', 'position' => 2]);
    $pending = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 103', 'position' => 3]);
    $ready = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 104', 'position' => 4]);

    floorOrderAt($tenant, $preparing, OrderStatus::Accepted);
    floorOrderAt($tenant, $pending, OrderStatus::Placed);
    floorOrderAt($tenant, $ready, OrderStatus::Ready);

    $order = array_map(
        static fn (array $card): string => $card['location']->name,
        app(ReadFloor::class)($tenant),
    );

    expect($order)->toBe(['Room 104', 'Room 103', 'Room 102', 'Room 101'])
        ->and($quiet->refresh()->position)->toBe(1);
});

it('reads two locations in the same state newest order first', function (): void {
    $tenant = Tenant::factory()->create();
    $older = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101', 'position' => 1]);
    $newer = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 102', 'position' => 2]);

    floorOrderAt($tenant, $older)->forceFill(['created_at' => Date::now()->subHours(2)])->save();
    floorOrderAt($tenant, $newer);

    $order = array_map(
        static fn (array $card): string => $card['location']->name,
        app(ReadFloor::class)($tenant),
    );

    expect($order)->toBe(['Room 102', 'Room 101']);
});

it("keeps the quiet locations in the tenant's own order among themselves", function (): void {
    $tenant = Tenant::factory()->create();

    foreach (['Room 101', 'Room 102', 'Room 103'] as $index => $name) {
        Location::factory()->ofTenant($tenant)->room()->create(['name' => $name, 'position' => $index]);
    }

    $order = array_map(
        static fn (array $card): string => $card['location']->name,
        app(ReadFloor::class)($tenant),
    );

    expect($order)->toBe(['Room 101', 'Room 102', 'Room 103']);
});

/*
|--------------------------------------------------------------------------
| What a card draws
|--------------------------------------------------------------------------
*/

it('draws a card per location with what is waiting at it', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 310']);

    floorOrderAt($tenant, $room, OrderStatus::Ready);
    floorOrderAt($tenant, $room, OrderStatus::Placed);

    enterTenantPanel($tenant, RoleEnum::Staff);

    floorOf()
        ->assertOk()
        ->assertSee('Room 204')
        ->assertSee('Room 310')
        ->assertSee('1 ready')
        ->assertSee('1 pending')
        ->assertSee('Nothing open');
});

it('shows no money anywhere on the floor', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);

    floorOrderAt($tenant, $room);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // Not the amount, and not the action that takes it: this view is about
    // where the work is (`.ai/rules/order-taking.md`).
    floorOf()
        ->assertDontSee('₹')
        ->assertActionDoesNotExist('settleAction');
});

it("says nothing more than 'nothing open' on a quiet card", function (): void {
    $tenant = Tenant::factory()->create();
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101']);

    enterTenantPanel($tenant, RoleEnum::Staff);

    floorOf()
        ->assertSee('Room 101')
        ->assertSee('Nothing open')
        ->assertDontSee('Clear');
});

it("shows nobody else's locations, and nobody else's orders against its own", function (): void {
    $tenant = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Ours 1']);
    $theirRoom = Location::factory()->ofTenant($theirs)->room()->create(['name' => 'Theirs 1']);

    floorOrderAt($theirs, $theirRoom);

    enterTenantPanel($tenant, RoleEnum::Staff);

    floorOf()
        ->assertSee('Ours 1')
        ->assertDontSee('Theirs 1');
});

it('narrows the floor by kind, by search and to what is open', function (): void {
    $tenant = Tenant::factory()->create(['type' => TenantType::Hotel]);
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204', 'code' => '204']);
    $pool = Location::factory()->ofTenant($tenant)->area()->create(['name' => 'Poolside']);

    floorOrderAt($tenant, $pool);

    enterTenantPanel($tenant, RoleEnum::Staff);

    floorOf()
        ->set('kind', 'room')
        ->assertSee('Room 204')
        ->assertDontSee('Poolside')
        ->set('kind', null)
        // The code staff actually type, not only the name a guest reads.
        ->set('floorSearch', '204')
        ->assertSee('Room 204')
        ->assertDontSee('Poolside')
        ->set('floorSearch', '')
        ->set('onlyOpen', true)
        ->assertSee('Poolside')
        ->assertDontSee('Room 204');
});

it('leaves a switched-off location off the floor', function (): void {
    $tenant = Tenant::factory()->create();
    Location::factory()->ofTenant($tenant)->room()->inactive()->create(['name' => 'Room 999']);

    enterTenantPanel($tenant, RoleEnum::Staff);

    floorOf()->assertDontSee('Room 999');
});

/*
|--------------------------------------------------------------------------
| Two ways of reading one page
|--------------------------------------------------------------------------
*/

it('opens as the list and switches to the floor and back', function (): void {
    $tenant = Tenant::factory()->create();
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101']);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::test(ListOrders::class);

    expect($page->instance()->isFloor())->toBeFalse();

    $page->call('showLayout', ListOrders::FLOOR)->assertSee('Room 101');

    expect($page->instance()->isFloor())->toBeTrue();

    // Back to the list: the floor's own words are gone. Not the room's name —
    // the table's location filter lists every location by name.
    $page->call('showLayout', ListOrders::LIST)->assertDontSee('Nothing open');
});

it('crosses from a card to the list with that location already filtered', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    floorOrderAt($tenant, $room);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = floorOf()->call('showOrdersAt', $room->getKey());

    expect($page->instance()->isFloor())->toBeFalse()
        ->and($page->get('tableFilters')['location_id']['value'])->toBe((string) $room->getKey());
});

it('sends a tenant with no locations to set some up', function (): void {
    $tenant = Tenant::factory()->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    floorOf()
        ->assertOk()
        ->assertSee('No locations yet')
        ->assertSee('New location');
});
