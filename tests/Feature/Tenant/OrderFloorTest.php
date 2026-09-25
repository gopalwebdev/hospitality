<?php

use App\Actions\Orders\ReadFloor;
use App\Actions\Orders\ReadLocationActivity;
use App\Actions\Payments\RecordPayment;
use App\Enums\LocationActivity;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role as RoleEnum;
use App\Enums\TenantType;
use App\Filament\Tenant\Resources\Orders\Pages\ListOrders;
use App\Models\Location;
use App\Models\Order;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
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
| Every room, table and delivery point at once, with what is open at each —
| one of the two ways ListOrders is read. The figures are derived from orders
| on every read, since locations carry nothing about occupancy, so these tests
| are mostly about what "open" means (a placed order that still owes money)
| and about the order the cards come back in.
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
 * A placed order at a location, owing exactly this much.
 */
function boardOrderAt(Tenant $tenant, ?Location $location, int $total): Order
{
    return Order::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'location_id' => $location?->getKey(),
        'status' => OrderStatus::Placed,
        'subtotal' => $total,
        'tax' => 0,
        'cgst' => 0,
        'sgst' => 0,
        'charges_total' => 0,
        'total' => $total,
    ]);
}

it('reads what is open at each location in one query', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create();
    $other = Location::factory()->ofTenant($tenant)->room()->create();

    boardOrderAt($tenant, $room, 50000);
    boardOrderAt($tenant, $room, 30000);
    boardOrderAt($tenant, $other, 20000);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $activity = app(ReadLocationActivity::class)($tenant);

    expect($queries)->toBe(1)
        ->and($activity[$room->getKey()]['openOrders'])->toBe(2)
        ->and($activity[$room->getKey()]['outstanding'])->toBe(80000)
        ->and($activity[$other->getKey()]['outstanding'])->toBe(20000);
});

it('leaves out a cancelled order and one already settled', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create();

    $cancelled = boardOrderAt($tenant, $room, 50000);
    $cancelled->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => Date::now()]);

    $settled = boardOrderAt($tenant, $room, 30000);
    app(RecordPayment::class)($tenant, PaymentMethod::Cash, 30000, [$settled->getKey() => 30000]);

    expect(app(ReadLocationActivity::class)($tenant))->toBe([]);
});

it('counts a part-paid order as open, for what is left of it', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create();

    $order = boardOrderAt($tenant, $room, 50000);
    app(RecordPayment::class)($tenant, PaymentMethod::Cash, 20000, [$order->getKey() => 20000]);

    $activity = app(ReadLocationActivity::class)($tenant);

    expect($activity[$room->getKey()]['openOrders'])->toBe(1)
        ->and($activity[$room->getKey()]['outstanding'])->toBe(30000);
});

it('reads as just ordered while the newest order is fresh, and running once it is not', function (): void {
    $tenant = Tenant::factory()->create();
    $fresh = Location::factory()->ofTenant($tenant)->room()->create();
    $older = Location::factory()->ofTenant($tenant)->room()->create();

    boardOrderAt($tenant, $fresh, 10000);

    $stale = boardOrderAt($tenant, $older, 10000);
    $stale->forceFill(['created_at' => Date::now()->subMinutes(ReadLocationActivity::NEW_ORDER_MINUTES + 5)])->save();

    $activity = app(ReadLocationActivity::class)($tenant);

    expect($activity[$fresh->getKey()]['state'])->toBe(LocationActivity::JustOrdered)
        ->and($activity[$older->getKey()]['state'])->toBe(LocationActivity::Running);
});

it('draws a card per location with what is open at it, and the floor summed above them', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 310']);

    boardOrderAt($tenant, $room, 124000);

    enterTenantPanel($tenant, RoleEnum::Staff);

    floorOf()
        ->assertOk()
        ->assertSee('Room 204')
        ->assertSee('Room 310')
        ->assertSee('₹1,240.00')
        ->assertSee('New order')
        ->assertSee('Nothing open');
});

it('shows nobody else\'s locations, and nobody else\'s orders against its own', function (): void {
    $tenant = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Ours 1']);
    Location::factory()->ofTenant($theirs)->room()->create(['name' => 'Theirs 1']);

    boardOrderAt($theirs, null, 90000);

    enterTenantPanel($tenant, RoleEnum::Staff);

    floorOf()
        ->assertSee('Ours 1')
        ->assertDontSee('Theirs 1')
        ->assertDontSee('₹900.00');
});

it('narrows the floor by kind, by search and to what is open', function (): void {
    $tenant = Tenant::factory()->create(['type' => TenantType::Hotel]);
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204', 'code' => '204']);
    $pool = Location::factory()->ofTenant($tenant)->area()->create(['name' => 'Poolside']);

    boardOrderAt($tenant, $pool, 10000);

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

it('settles a location from its own card', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    $order = boardOrderAt($tenant, $room, 40000);

    enterTenantPanel($tenant, RoleEnum::Staff);

    floorOf()
        ->callAction(
            TestAction::make('settleAction')->arguments(['location' => $room->getKey()]),
            ['orders' => [$order->getKey()], 'method' => PaymentMethod::Cash->value, 'amount' => 400],
        )
        ->assertHasNoActionErrors();

    expect($order->refresh()->amountOutstanding())->toBe(0);
});

it('sends a tenant with no locations to set some up', function (): void {
    $tenant = Tenant::factory()->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    floorOf()
        ->assertOk()
        ->assertSee('No locations yet')
        ->assertSee('New location');
});

/*
|--------------------------------------------------------------------------
| The order the cards come back in, and what a quiet one says
|--------------------------------------------------------------------------
|
| The project owner's instruction: a room with nothing open is not what staff
| are looking for, so it goes last — and it says so once, rather than carrying
| a badge and a total of nothing.
|
*/

it('puts the locations with something owing first, newest order at the top', function (): void {
    $tenant = Tenant::factory()->create();
    $quiet = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101', 'position' => 1]);
    $older = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 102', 'position' => 2]);
    $newest = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 103', 'position' => 3]);

    $stale = boardOrderAt($tenant, $older, 10000);
    $stale->forceFill(['created_at' => Date::now()->subHours(2)])->save();

    boardOrderAt($tenant, $newest, 20000);

    $order = array_map(
        static fn (array $card): string => $card['location']->name,
        app(ReadFloor::class)($tenant),
    );

    // The two with something owing, newest first — then the quiet one.
    expect($order)->toBe(['Room 103', 'Room 102', 'Room 101'])
        ->and($quiet->refresh()->position)->toBe(1);
});

it('keeps the quiet locations in the tenant\'s own order among themselves', function (): void {
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

it('says nothing more than "nothing open" on a quiet card', function (): void {
    $tenant = Tenant::factory()->create();
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101']);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // No "Clear" badge, and no total of nothing beside it.
    floorOf()
        ->assertSee('Room 101')
        ->assertSee('Nothing open')
        ->assertDontSee('Clear')
        ->assertDontSee('₹0.00');
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
    boardOrderAt($tenant, $room, 40000);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = floorOf()->call('showOrdersAt', $room->getKey());

    expect($page->instance()->isFloor())->toBeFalse()
        ->and($page->get('tableFilters')['location_id']['value'])->toBe((string) $room->getKey());
});
