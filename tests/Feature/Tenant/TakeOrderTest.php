<?php

use App\Actions\Baskets\PriceBasket;
use App\Enums\FilamentPanel;
use App\Enums\OrderSettlement;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Enums\Weekday;
use App\Filament\Tenant\Pages\TakeOrder;
use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Filament\Tenant\Resources\Orders\Pages\ListOrders;
use App\Models\Charge;
use App\Models\Location;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantOpeningHour;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Taking an order in the panel
|--------------------------------------------------------------------------
|
| Staff take orders at the desk and over the phone. The counter is the same
| PriceBasket the guest app posts to and the same PlaceOrder its API calls,
| so what matters here is that the two agree to the rupee — and that the one
| rule the panel relaxes, the clock, is the only one it relaxes.
|
*/

/**
 * A tenant with one menu holding one category, ready to be ordered from.
 *
 * @return array{0: Tenant, 1: Menu, 2: MenuCategory}
 */
function counterFor(int $taxRate = 500): array
{
    $tenant = Tenant::factory()->create();
    taxTenantAt($tenant, $taxRate);

    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    return [$tenant, $menu, $category];
}

/**
 * An order still running at a location.
 */
function runningOrderAt(Tenant $tenant, Location $location, OrderStatus $status = OrderStatus::Placed): Order
{
    return Order::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'location_id' => $location->getKey(),
        'location_name' => ['en' => $location->name],
        'status' => $status,
        'subtotal' => 26000,
        'tax' => 0,
        'cgst' => 0,
        'sgst' => 0,
        'charges_total' => 0,
        'total' => 26000,
    ]);
}

it('draws the menu a guest could order from, and leaves out what they could not', function (): void {
    [$tenant, , $category] = counterFor();

    MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Paneer Tikka'], 'price' => 24900]);
    MenuItem::factory()->inCategory($category)->unavailable()->create(['name' => ['en' => 'Sold Out Biryani']]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(TakeOrder::class)
        ->assertOk()
        ->assertSee('Paneer Tikka')
        ->assertSee('₹249.00')
        ->assertDontSee('Sold Out Biryani');
});

it('prices the basket to the rupee the guest app would have shown', function (): void {
    [$tenant, $menu, $category] = counterFor(taxRate: 1800);

    $item = MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Biryani'], 'price' => 25000]);
    Charge::factory()->ofTenant($tenant)->percentage(1000)->onMenus($menu)->create(['name' => ['en' => 'Service charge']]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::test(TakeOrder::class)
        ->call('addTile', 'item', $item->getKey())
        ->call('increment', 'item:'.$item->getKey());

    // What the guest's own phone would have been told, for the very same lines.
    $guest = app(PriceBasket::class)($tenant, $menu, [[
        'key' => 'item:'.$item->getKey(),
        'type' => PriceBasket::ITEM,
        'id' => $item->getKey(),
        'quantity' => 2,
        'choices' => [],
    ]]);

    $page->assertSee('₹500.00')      // subtotal
        ->assertSee('₹50.00')        // the 10% service charge
        ->assertSee('₹99.00');       // 18% GST on both

    expect($guest['total'])->toBe(64900)
        ->and($page->instance()->priced()['total'])->toBe($guest['total'])
        ->and($page->instance()->priced()['tax'])->toBe($guest['tax']);
});

it('adds an item with its add-ons, and keeps two different sets of choices apart', function (): void {
    [$tenant, , $category] = counterFor();

    $item = MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Biryani'], 'price' => 25000]);
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: true, max: 1)->create(['name' => ['en' => 'Spice level']]);
    $mild = MenuAddOnOption::factory()->inGroup($group)->create(['name' => ['en' => 'Mild'], 'price' => 0]);
    $hot = MenuAddOnOption::factory()->inGroup($group)->create(['name' => ['en' => 'Hot'], 'price' => 5000]);

    MenuItemAddOnGroup::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'menu_item_id' => $item->getKey(),
        'menu_add_on_group_id' => $group->getKey(),
    ]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::test(TakeOrder::class)
        ->callAction(
            TestAction::make('customiseAction')->arguments(['item' => $item->getKey()]),
            ['quantity' => 1, 'group_'.$group->getKey() => $mild->getKey()],
        )
        ->callAction(
            TestAction::make('customiseAction')->arguments(['item' => $item->getKey()]),
            ['quantity' => 1, 'group_'.$group->getKey() => $hot->getKey()],
        );

    // Mild and Hot are two lines of the same item, not one line of two.
    expect($page->get('lines'))->toHaveCount(2)
        ->and($page->instance()->priced()['subtotal'])->toBe(25000 + 30000);

    $page->assertSee('Mild')->assertSee('Hot');
});

it('steps a line up and down, and takes it out at nothing', function (): void {
    [$tenant, , $category] = counterFor();
    $item = MenuItem::factory()->inCategory($category)->create(['price' => 10000]);
    $key = 'item:'.$item->getKey();

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::test(TakeOrder::class)
        ->call('addTile', 'item', $item->getKey())
        ->call('increment', $key)
        ->call('increment', $key);

    expect($page->get('lines')[0]['quantity'])->toBe(3);

    $page->call('decrement', $key)
        ->call('decrement', $key)
        ->call('decrement', $key);

    expect($page->get('lines'))->toBe([]);
});

it('places the order where it was told to, and takes what it needs from stock', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    $item = MenuItem::factory()->inCategory($category)->stocked(5)->create(['price' => 20000]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(TakeOrder::class)
        ->set('data.location_id', $room->getKey())
        ->set('data.settlement', OrderSettlement::PayNow->value)
        ->set('data.note', 'No onions')
        ->call('addTile', 'item', $item->getKey())
        ->call('increment', 'item:'.$item->getKey())
        ->call('placeOrder')
        // Staff stay at the counter, ready for the next one — there is no
        // order page to be sent to any more.
        ->assertNoRedirect()
        ->assertSet('lines', []);

    $order = Order::query()->latest('id')->firstOrFail();

    expect($order->location_id)->toBe($room->getKey())
        ->and($order->location_name)->toBe('Room 204')
        ->and($order->settlement)->toBe(OrderSettlement::PayNow)
        ->and($order->note)->toBe('No onions')
        ->and($order->status)->toBe(OrderStatus::Placed)
        ->and($order->lines()->sum('quantity'))->toBe(2)
        ->and($item->refresh()->stock_quantity)->toBe(3);
});

it('names where an order goes in free text when nothing was picked', function (): void {
    [$tenant, , $category] = counterFor();
    $item = MenuItem::factory()->inCategory($category)->create(['price' => 20000]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(TakeOrder::class)
        ->set('data.location_label', 'Table by the window')
        ->call('addTile', 'item', $item->getKey())
        ->call('placeOrder');

    expect(Order::query()->latest('id')->firstOrFail()->location_name)->toBe('Table by the window');
});

it('takes an order past closing, and says so on the page', function (): void {
    [$tenant, , $category] = counterFor();
    $item = MenuItem::factory()->inCategory($category)->create(['price' => 20000]);

    // Shut every day: a guest's phone would be refused with StoreClosed.
    foreach (Weekday::cases() as $weekday) {
        TenantOpeningHour::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'weekday' => $weekday,
            'is_closed' => true,
            'opens_at' => null,
            'closes_at' => null,
        ]);
    }

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(TakeOrder::class)
        ->assertSee('Outside opening hours')
        ->call('addTile', 'item', $item->getKey())
        ->call('placeOrder')
        ->assertNoRedirect();

    expect(Order::query()->count())->toBe(1);
});

it('refuses an order asking for more than is left, and writes nothing', function (): void {
    [$tenant, , $category] = counterFor();
    $item = MenuItem::factory()->inCategory($category)->stocked(1)->create(['name' => ['en' => 'Last Biryani'], 'price' => 20000]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::test(TakeOrder::class)
        ->call('addTile', 'item', $item->getKey())
        ->call('increment', 'item:'.$item->getKey());

    // Somebody else takes the last one between the pricing and the button.
    $item->forceFill(['stock_quantity' => 0])->saveQuietly();

    $page->call('placeOrder')->assertNoRedirect();

    expect(Order::query()->count())->toBe(0)
        ->and($item->refresh()->stock_quantity)->toBe(0);
});

it('stops adding an item at the tenant\'s own cap on it', function (): void {
    [$tenant, , $category] = counterFor();
    $item = MenuItem::factory()->inCategory($category)->limitedPerOrder(2)->create(['price' => 10000]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(TakeOrder::class)
        ->call('addTile', 'item', $item->getKey())
        ->call('increment', 'item:'.$item->getKey())
        // Greyed rather than hidden: a missing button reads as sold out.
        ->assertSee('Limit reached');
});

it('empties the basket when the menu is switched', function (): void {
    [$tenant, $menu, $category] = counterFor();
    $other = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $item = MenuItem::factory()->inCategory($category)->create(['price' => 10000]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // The page opens on whichever menu sorts first by name, so the one the
    // item is actually on is chosen rather than assumed.
    $page = Livewire::test(TakeOrder::class)
        ->set('data.menu_id', $menu->getKey())
        ->call('addTile', 'item', $item->getKey());

    expect($page->get('lines'))->toHaveCount(1);

    $page->set('data.menu_id', $other->getKey());

    expect($page->get('lines'))->toBe([]);
});

it('opens on the place a link named', function (): void {
    [$tenant] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 310']);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::withQueryParams(['location' => $room->getKey()])->test(TakeOrder::class);

    // Taking an order is one page now, so the place is a field on it rather
    // than a screen in front of it — but `?location=` still opens on one.
    expect((int) $page->get('data.location_id'))->toBe($room->getKey());
});

it('ignores a location belonging to somebody else', function (): void {
    [$tenant] = counterFor();
    $theirs = Location::factory()->ofTenant(Tenant::factory()->create())->room()->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::withQueryParams(['location' => $theirs->getKey()])->test(TakeOrder::class);

    expect($page->get('data.location_id'))->toBeNull();
});

it('offers New order on the orders page to staff', function (): void {
    [$tenant] = counterFor();
    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(ListOrders::class)
        ->assertOk()
        ->assertActionVisible('takeOrder');
});

it('withholds New order from someone who may only read orders', function (): void {
    [$tenant] = counterFor();

    // Reading orders is not taking them: OrderCreate is what the button and
    // the page behind it both hang on.
    $reader = User::factory()->ofTenant($tenant)->create();
    $reader->givePermissionTo(Permission::OrderViewAny->value);

    $this->actingAs($reader);
    Filament::setCurrentPanel(FilamentPanel::Tenant->value);
    Filament::bootCurrentPanel();
    Filament::setTenant($tenant);

    Livewire::test(ListOrders::class)->assertActionHidden('takeOrder');

    expect(TakeOrder::canAccess())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Picking where it goes
|--------------------------------------------------------------------------
|
| A grid of cards rather than a select, so staff see what is already open at
| a room before they add to it. The page asks this first and shows the menu
| only once it is answered.
|
*/

it('offers every place as one grouped, searchable field', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101', 'code' => '101']);
    Location::factory()->ofTenant($tenant)->area()->create(['name' => 'Poolside']);
    MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Masala Dosa']]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::test(TakeOrder::class);

    // It was a full-screen grid of cards asked before the menu appeared;
    // with Places gone the argument for it went too, so taking an order is
    // one page and the place is a field on it. Grouped by kind, and the
    // card is on screen from the start.
    expect($page->instance()->locationOptions())->toHaveKeys(['Room', 'Area'])
        // The code is on the label only where the name does not already
        // carry it, so this one reads "Room 101" rather than "Room 101 · 101".
        ->and($page->instance()->locationOptions()['Room'])->toBe([$room->getKey() => 'Room 101']);

    $page->assertSee('Masala Dosa');
});
it('shows what is already running at a location once it is picked', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Masala Dosa']]);

    $running = Order::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'location_id' => $room->getKey(),
        'status' => OrderStatus::Placed,
        'subtotal' => 26000,
        'tax' => 0,
        'cgst' => 0,
        'sgst' => 0,
        'charges_total' => 0,
        'total' => 26000,
    ]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(TakeOrder::class)
        ->set('data.location_id', $room->getKey())
        ->assertSee('Room 204')
        ->assertSee('Orders here today')
        ->assertSee($running->reference())
        // Its state, and what it still owes — the counter is where a bill is
        // being built, so money belongs here even though the floor has none.
        ->assertSee('Pending')
        ->assertSee('₹260.00')
        // And the card is now on screen to order from.
        ->assertSee('Masala Dosa');
});

it('lists everything a place has taken today, whatever state each is in', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Masala Dosa']]);

    $waiting = runningOrderAt($tenant, $room, OrderStatus::Placed);
    $cooking = runningOrderAt($tenant, $room, OrderStatus::Accepted);
    $done = runningOrderAt($tenant, $room, OrderStatus::Served);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // Pressing a place asks "what is going on here", so a **served** order is
    // listed too, with its status beside it. Leaving it out answered "what
    // still needs work", which is a different question.
    Livewire::test(TakeOrder::class)
        ->set('data.location_id', $room->getKey())
        ->assertSee('Orders here today')
        ->assertSee($waiting->reference())
        ->assertSee($cooking->reference())
        ->assertSee($done->reference())
        ->assertSee('Pending')
        ->assertSee('Preparing')
        ->assertSee('Served')
        // Only the three still being worked are counted as open above them.
        ->assertSee('2 orders');
});

it('offers no dead controls on an order there is nothing left to do to', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    MenuItem::factory()->inCategory($category)->create();

    $done = runningOrderAt($tenant, $room, OrderStatus::Served);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $html = (string) Livewire::test(TakeOrder::class)
        ->set('data.location_id', $room->getKey())
        ->assertSee($done->reference())
        ->html();

    // An action echoed straight into Blade renders **disabled** when its
    // visible() is false, rather than rendering nothing — Filament leaves
    // that filtering to whatever holds the action, and here that is a
    // foreach. A served order was drawing a dead Move along and a dead
    // Change beside it, which is exactly the clutter this panel is for
    // avoiding.
    expect($html)->not->toContain('fi-disabled');
});

it('says so plainly when a place has taken nothing today', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    MenuItem::factory()->inCategory($category)->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(TakeOrder::class)
        ->set('data.location_id', $room->getKey())
        ->assertSee('Nothing ordered here today.');
});

it('lets staff name somewhere that is not one of the rows', function (): void {
    [$tenant, , $category] = counterFor();
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101']);
    $item = MenuItem::factory()->inCategory($category)->create(['price' => 20000]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // Somewhere that is not one of the rows: the select is left empty and
    // the name is typed beside it.
    Livewire::test(TakeOrder::class)
        ->set('data.location_id', null)
        ->set('data.location_label', 'Terrace, far table')
        ->call('addTile', 'item', $item->getKey())
        ->call('placeOrder');

    $order = Order::query()->latest('id')->firstOrFail();

    expect($order->location_id)->toBeNull()
        ->and($order->location_name)->toBe('Terrace, far table');
});

it('keeps the basket when the place is changed', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101']);
    $other = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 102']);
    $item = MenuItem::factory()->inCategory($category)->create(['price' => 20000]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::test(TakeOrder::class)
        ->set('data.location_id', $room->getKey())
        ->call('addTile', 'item', $item->getKey())
        ->set('data.location_id', $other->getKey());

    // Changing their mind about the table is not changing their mind about
    // the order.
    expect($page->get('lines'))->toHaveCount(1);
});
it("never offers another tenant's place", function (): void {
    [$tenant] = counterFor();
    $ours = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Ours 1']);
    $theirs = Location::factory()->ofTenant(Tenant::factory()->create())->room()->create(['name' => 'Theirs 1']);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $offered = collect(Livewire::test(TakeOrder::class)->instance()->locationOptions())
        ->flatMap(static fn (array $group): array => array_keys($group))
        ->all();

    expect($offered)->toContain($ours->getKey())
        ->and($offered)->not->toContain($theirs->getKey());
});
it('skips the question for a tenant with no locations at all', function (): void {
    [$tenant, , $category] = counterFor();
    MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Masala Dosa']]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(TakeOrder::class)
        ->assertDontSee('Where is this order going?')
        ->assertSee('Masala Dosa')
        // Nothing to pick from, so where it goes is typed beside the order.
        ->assertSee('Where it goes');
});

it('reads one of the running orders in a modal, without leaving the counter', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Masala Dosa'], 'price' => 12500]);

    $running = Order::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'location_id' => $room->getKey(),
        // The order keeps its own copy of where it went, so the factory's is
        // set too rather than inferred from the location it names.
        'location_name' => ['en' => 'Room 204'],
        'status' => OrderStatus::Placed,
        'subtotal' => 12500,
        'tax' => 0,
        'cgst' => 0,
        'sgst' => 0,
        'charges_total' => 0,
        'total' => 12500,
    ]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::test(TakeOrder::class)
        ->set('data.location_id', $room->getKey())
        ->mountAction(TestAction::make('viewOrder')->arguments(['order' => $running->getKey()]));

    $html = (string) collect($page->effects['partials'] ?? [])
        ->first(fn (mixed $partial, string $key): bool => str_starts_with($key, 'action-modals'));

    expect($html)->toContain('Order '.$running->reference())
        ->and($html)->toContain('Room 204');
});

/*
|--------------------------------------------------------------------------
| Pressing a place on the floor
|--------------------------------------------------------------------------
|
| A floor card is one link and its only destination is here, so everything a
| member of staff went to the room to do has to be doable from this page:
| see what is running there, move one of those orders along, change one the
| kitchen has not taken yet, and take the next one.
|
*/

it('links on to every order a place has taken, with the list already filtered', function (): void {
    [$tenant] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    $other = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 310']);

    $here = runningOrderAt($tenant, $room);
    $elsewhere = runningOrderAt($tenant, $other);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $url = Livewire::test(TakeOrder::class)
        ->set('data.location_id', $room->getKey())
        ->instance()
        ->ordersHereUrl($room);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);

    // Read the state back rather than trusting the shape of the link: the
    // key has to be `filters`, and `tableFilters` would not be an error but
    // an unread parameter and a list showing every order
    // (`.ai/rules/tables.md`).
    Livewire::withQueryParams($parameters)
        ->test(ListOrders::class)
        ->assertCanSeeTableRecords([$here])
        ->assertCanNotSeeTableRecords([$elsewhere]);
});

it('takes an order at a place and then changes it, without leaving the counter', function (): void {
    // Untaxed, so the totals below are the journey rather than the GST,
    // which BasketPriceTest and the pricing tests above already cover.
    [$tenant, , $category] = counterFor(0);
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    $dosa = MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Masala Dosa'], 'price' => 12000]);
    $vada = MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Medu Vada'], 'price' => 7000]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // Take one, the way a member of staff who pressed the room would.
    Livewire::withQueryParams(['location' => $room->getKey()])
        ->test(TakeOrder::class)
        ->call('addTile', 'item', $dosa->getKey())
        ->call('placeOrder')
        ->assertSet('lines', [])
        // It lands in "already running here" a line below, which is what
        // staying at the counter is for.
        ->assertSee('Orders here today');

    $order = Order::query()->latest('id')->firstOrFail();

    expect($order->location_id)->toBe($room->getKey())
        ->and($order->status)->toBe(OrderStatus::Placed)
        ->and($order->total)->toBe(12000);

    // Change it: the Change button is a link back here with the order loaded.
    Livewire::withQueryParams(['order' => $order->getKey()])
        ->test(TakeOrder::class)
        ->assertSee('Masala Dosa')
        ->call('addTile', 'item', $vada->getKey())
        ->call('placeOrder')
        ->assertRedirect(OrderResource::getUrl('index'));

    expect($order->refresh()->total)->toBe(19000)
        ->and($order->lines()->count())->toBe(2);
});

it('refuses to change an order the kitchen has taken, from the counter too', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    MenuItem::factory()->inCategory($category)->create(['price' => 12000]);

    $accepted = runningOrderAt($tenant, $room, OrderStatus::Accepted);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // "If not preparing": the kitchen has this one, so the link opens a new
    // order at that room rather than silently editing something being made.
    Livewire::withQueryParams(['order' => $accepted->getKey()])
        ->test(TakeOrder::class)
        ->assertSet('orderId', null);
});

it('offers every tile the same icon button, whether or not it has add-ons', function (): void {
    [$tenant, , $category] = counterFor();
    $plain = MenuItem::factory()->inCategory($category)->create(['price' => 7000]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // "Choose" was a worded button beside a plain round Add, so two tiles
    // side by side ended in controls of different widths.
    $customise = Livewire::test(TakeOrder::class)->instance()->customiseAction();

    expect($customise->isIconButton())->toBeTrue()
        ->and($plain->exists)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The counter's own chrome
|--------------------------------------------------------------------------
*/

it('keeps Orders lit in the sidebar while an order is being taken', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    MenuItem::factory()->inCategory($category)->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    $html = (string) $this->get(TakeOrder::getUrl().'?location='.$room->getKey())
        ->assertOk()
        ->getContent();

    // The counter registers no navigation item of its own, so nothing at all
    // was highlighted for the whole time an order was being taken — and
    // pressing a place on Places opens the counter, so that is most of a
    // shift. OrderResource::getNavigationItemActiveRoutePattern() names this
    // route as well as its own.
    expect($html)->toMatch('/fi-sidebar-item fi-active[^>]*>\s*<a\s+href="[^"]*\/dashboard\/orders"/');
});

it('draws no page heading, and puts Back at the top left of the page itself', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    MenuItem::factory()->inCategory($category)->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    $html = (string) $this->get(TakeOrder::getUrl().'?location='.$room->getKey())
        ->assertOk()
        ->getContent();

    // "Take order · Room 101" cost a line of screen to repeat what the panel
    // on the right already says. With no heading and no header actions
    // Filament draws no header at all, so Back is the page's own — top left,
    // where the project owner asked for it.
    expect($html)->not->toContain('Take order ·')
        ->and($html)->not->toContain('fi-header-heading')
        ->and($html)->toContain('to-top');
});

it('counts and lists the same orders: today\'s, and no others', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    MenuItem::factory()->inCategory($category)->create();

    $today = runningOrderAt($tenant, $room, OrderStatus::Placed);

    // Still underway, but taken yesterday. It used to be counted on the card
    // and then missing from the list you opened to find it, because the card
    // counted every underway order and the panel listed only today's.
    $yesterday = runningOrderAt($tenant, $room, OrderStatus::Placed);
    $yesterday->forceFill(['created_at' => Date::now()->subDay()])->save();

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::test(TakeOrder::class)->set('data.location_id', $room->getKey());

    expect($page->instance()->ordersHere()->pluck('id')->all())->toBe([$today->getKey()])
        // The heading reads the very figure the card draws, so the two
        // cannot disagree again.
        ->and($page->instance()->openOrdersHereCount())->toBe(1);

    $page->assertSee($today->reference())
        ->assertDontSee($yesterday->reference())
        ->assertSee('1 order');
});

it('puts the still-open orders first, so the cap only ever cuts finished ones', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    MenuItem::factory()->inCategory($category)->create();

    // Ten served orders, then one still waiting — the oldest row of the day.
    foreach (range(1, 10) as $ignored) {
        runningOrderAt($tenant, $room, OrderStatus::Served);
    }

    $waiting = runningOrderAt($tenant, $room, OrderStatus::Placed);
    $waiting->forceFill(['created_at' => Date::now()->subHours(6)])->save();

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::test(TakeOrder::class)->set('data.location_id', $room->getKey());

    // Newest-first alone would have pushed the one order somebody is standing
    // there asking about off the end of a capped list.
    expect($page->instance()->ordersHere()->first()?->getKey())->toBe($waiting->getKey())
        ->and($page->instance()->hasMoreOrdersHere())->toBeTrue();

    $page->assertSee($waiting->reference())
        ->assertSee('See every order here');
});
