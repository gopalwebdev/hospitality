<?php

use App\Actions\Orders\ReadFloor;
use App\Actions\Orders\ReadLocationActivity;
use App\Enums\LocationActivity;
use App\Enums\OrderStatus;
use App\Enums\Role as RoleEnum;
use App\Enums\TenantType;
use App\Filament\Tenant\Pages\TakeOrder;
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
| The orders page, read as Places
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
 * The orders page, switched to its Places layout.
 */
function placesOf(): Testable
{
    return Livewire::test(ListOrders::class)->call('showLayout', ListOrders::PLACES);
}

/**
 * An order at a location, in whatever state it is being worked.
 */
function orderPlacedAt(Tenant $tenant, ?Location $location, OrderStatus $status = OrderStatus::Placed): Order
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

    orderPlacedAt($tenant, $room, OrderStatus::Placed);
    orderPlacedAt($tenant, $room, OrderStatus::Placed);
    orderPlacedAt($tenant, $room, OrderStatus::Ready);
    orderPlacedAt($tenant, $other, OrderStatus::Accepted);

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
    orderPlacedAt($tenant, $ready, OrderStatus::Ready);
    orderPlacedAt($tenant, $ready, OrderStatus::Placed);
    orderPlacedAt($tenant, $ready, OrderStatus::Placed);

    orderPlacedAt($tenant, $pending, OrderStatus::Placed);
    orderPlacedAt($tenant, $pending, OrderStatus::Accepted);

    orderPlacedAt($tenant, $preparing, OrderStatus::Accepted);

    $activity = app(ReadLocationActivity::class)($tenant);

    expect($activity[$ready->getKey()]['state'])->toBe(LocationActivity::Ready)
        ->and($activity[$pending->getKey()]['state'])->toBe(LocationActivity::Pending)
        ->and($activity[$preparing->getKey()]['state'])->toBe(LocationActivity::Preparing);
});

it('leaves out an order that has been served or cancelled', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create();

    orderPlacedAt($tenant, $room, OrderStatus::Served);
    orderPlacedAt($tenant, $room, OrderStatus::Cancelled);

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

    orderPlacedAt($tenant, $preparing, OrderStatus::Accepted);
    orderPlacedAt($tenant, $pending, OrderStatus::Placed);
    orderPlacedAt($tenant, $ready, OrderStatus::Ready);

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

    orderPlacedAt($tenant, $older)->forceFill(['created_at' => Date::now()->subHours(2)])->save();
    orderPlacedAt($tenant, $newer);

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

it('draws a card per location, coloured by what is happening at it', function (): void {
    $tenant = Tenant::factory()->create();
    $busy = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 310']);

    orderPlacedAt($tenant, $busy, OrderStatus::Ready);
    orderPlacedAt($tenant, $busy, OrderStatus::Placed);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $html = (string) placesOf()
        ->assertOk()
        ->assertSee('Room 204')
        ->assertSee('Room 310')
        ->html();

    // The loudest of what is there decides the colour, and a room with
    // nothing underway is drawn plain.
    expect($html)->toContain('lc--'.LocationActivity::Ready->value)
        ->and($html)->toContain('lc--'.LocationActivity::Clear->value);
});

it('shows how many orders are open on the card, and nothing else that varies', function (): void {
    $tenant = Tenant::factory()->create();
    $busy = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 310']);

    orderPlacedAt($tenant, $busy, OrderStatus::Ready);
    orderPlacedAt($tenant, $busy, OrderStatus::Placed);
    orderPlacedAt($tenant, $busy, OrderStatus::Accepted);
    // Served is finished business, so it is not open and is not counted.
    orderPlacedAt($tenant, $busy, OrderStatus::Served);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $html = (string) placesOf()->html();

    // The project owner's rule for these cards: every one the same shape, and
    // only the colour and the count differ between them.
    expect($html)->toContain('lc-count lc-count--ready')
        ->and(substr_count($html, 'lc-count lc-count--'))->toBe(1)
        ->and($html)->toContain('>3</span>');
});

it('says what is happening in colour, and writes it out only for a screen reader', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);

    orderPlacedAt($tenant, $room, OrderStatus::Placed);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $html = (string) placesOf()->html();

    // The project owner's instruction: no badge, no chip per step and no
    // "how long ago" on a card — those made a busy card twice the height of
    // a quiet one. Colour carries it, and pressing the card says it in full.
    // Colour alone is no use to a screen reader, so the words are still
    // there, once, hidden.
    expect(substr_count($html, '1 pending'))->toBe(1)
        ->and($html)->toContain('fi-sr-only');
});

it('shows no money anywhere on the floor', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);

    orderPlacedAt($tenant, $room);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // Not the amount, and not the action that takes it: this view is about
    // where the work is (`.ai/rules/order-taking.md`).
    placesOf()
        ->assertDontSee('₹')
        ->assertActionDoesNotExist('settleAction');
});

it('draws nothing but its name on a quiet card', function (): void {
    $tenant = Tenant::factory()->create();
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101']);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // It carried a "Clear" badge, then a "Nothing open" line in its place, and
    // the project owner had both off for the same reason: a line drawn on
    // almost every card at once says nothing. An empty card is the message.
    placesOf()
        ->assertSee('Room 101')
        ->assertDontSee('Nothing open')
        ->assertDontSee('Clear');
});

it('draws nothing on a card but its name', function (): void {
    $tenant = Tenant::factory()->create();
    Location::factory()->ofTenant($tenant)->room()->create([
        'name' => 'Penthouse',
        'code' => 'ZZ9',
        'capacity' => 4,
    ]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // "Room" under "Room 101" was the same word twice and "Area" under
    // "Poolside" was a label nobody reads second, so the kind is the icon
    // alone. The code and the capacity went with them: the search matches a
    // code whether or not the card prints it.
    placesOf()
        ->assertSee('Penthouse')
        ->assertDontSee('ZZ9')
        ->assertDontSee('4 seats')
        ->assertDontSee('Nothing open');
});

it("shows nobody else's locations, and nobody else's orders against its own", function (): void {
    $tenant = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Ours 1']);
    $theirRoom = Location::factory()->ofTenant($theirs)->room()->create(['name' => 'Theirs 1']);

    orderPlacedAt($theirs, $theirRoom);

    enterTenantPanel($tenant, RoleEnum::Staff);

    placesOf()
        ->assertSee('Ours 1')
        ->assertDontSee('Theirs 1');
});

it('narrows the floor by kind, by search and to what is open', function (): void {
    $tenant = Tenant::factory()->create(['type' => TenantType::Hotel]);
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204', 'code' => '204']);
    $pool = Location::factory()->ofTenant($tenant)->area()->create(['name' => 'Poolside']);

    orderPlacedAt($tenant, $pool);

    enterTenantPanel($tenant, RoleEnum::Staff);

    placesOf()
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

    placesOf()->assertDontSee('Room 999');
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

    expect($page->instance()->isPlaces())->toBeFalse();

    $page->call('showLayout', ListOrders::PLACES)->assertSee('Room 101');

    expect($page->instance()->isPlaces())->toBeTrue();

    // Back to the list: the floor's own words are gone. Not the room's name —
    // the table's location filter lists every location by name.
    $page->call('showLayout', ListOrders::LIST)->assertDontSee('Nothing open');
});

it('opens the counter at that place when a card is pressed', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    orderPlacedAt($tenant, $room);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // The whole card, rather than a "Take order" button on it and an icon
    // button beside that: the card was the obvious thing to press and
    // pressing it did nothing. What is running at the room, moving one of
    // those orders along and changing one are all on the counter.
    placesOf()->assertSeeHtml('href="'.TakeOrder::getUrl().'?location='.$room->getKey().'"');
});

it('offers no buttons on a card at all', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    orderPlacedAt($tenant, $room);

    enterTenantPanel($tenant, RoleEnum::Staff);

    placesOf()
        ->assertDontSee('Take order')
        ->assertDontSee('Show its orders');
});

it('sends a tenant with no locations to set some up', function (): void {
    $tenant = Tenant::factory()->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    placesOf()
        ->assertOk()
        ->assertSee('No locations yet')
        ->assertSee('New location');
});
