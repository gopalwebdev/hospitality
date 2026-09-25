<?php

use App\Actions\Baskets\PriceBasket;
use App\Enums\FilamentPanel;
use App\Enums\OrderSettlement;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Enums\Weekday;
use App\Filament\Tenant\Pages\TakeOrder;
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
        ->call('chooseLocation', $room->getKey())
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

it('opens with the location a floor card sent it to', function (): void {
    [$tenant] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 310']);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::withQueryParams(['location' => $room->getKey()])->test(TakeOrder::class);

    expect($page->get('locationId'))->toBe($room->getKey())
        ->and($page->instance()->isPickingLocation())->toBeFalse();
});

it('ignores a location belonging to somebody else', function (): void {
    [$tenant] = counterFor();
    $theirs = Location::factory()->ofTenant(Tenant::factory()->create())->room()->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::withQueryParams(['location' => $theirs->getKey()])->test(TakeOrder::class);

    expect($page->get('locationId'))->toBeNull();
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

it('asks where the order goes first, as a grid of every location', function (): void {
    [$tenant, , $category] = counterFor();
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101']);
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 102']);
    MenuItem::factory()->inCategory($category)->create(['name' => ['en' => 'Masala Dosa']]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(TakeOrder::class)
        ->assertOk()
        ->assertSee('Where is this order going?')
        ->assertSee('Room 101')
        ->assertSee('Room 102')
        ->assertSee('Somewhere else')
        // The card is not offered until there is somewhere to send it.
        ->assertDontSee('Masala Dosa');
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
        ->call('chooseLocation', $room->getKey())
        ->assertSee('Room 204')
        ->assertSee('Already running here')
        ->assertSee('#'.$running->getKey())
        // Its state, and what it still owes — the counter is where a bill is
        // being built, so money belongs here even though the floor has none.
        ->assertSee('Pending')
        ->assertSee('₹260.00')
        // And the card is now on screen to order from.
        ->assertSee('Masala Dosa');
});

it('lets staff name somewhere that is not one of the rows', function (): void {
    [$tenant, , $category] = counterFor();
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101']);
    $item = MenuItem::factory()->inCategory($category)->create(['price' => 20000]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(TakeOrder::class)
        ->call('chooseElsewhere')
        ->set('data.location_label', 'Terrace, far table')
        ->call('addTile', 'item', $item->getKey())
        ->call('placeOrder');

    $order = Order::query()->latest('id')->firstOrFail();

    expect($order->location_id)->toBeNull()
        ->and($order->location_name)->toBe('Terrace, far table');
});

it('goes back to the grid without losing the basket', function (): void {
    [$tenant, , $category] = counterFor();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101']);
    $item = MenuItem::factory()->inCategory($category)->create(['price' => 20000]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::test(TakeOrder::class)
        ->call('chooseLocation', $room->getKey())
        ->call('addTile', 'item', $item->getKey())
        ->call('changeLocation');

    // Changing their mind about the table is not changing their mind about
    // the order.
    expect($page->get('lines'))->toHaveCount(1)
        ->and($page->instance()->isPickingLocation())->toBeTrue();
});

it('refuses a location belonging to another tenant', function (): void {
    [$tenant] = counterFor();
    Location::factory()->ofTenant($tenant)->room()->create();
    $theirs = Location::factory()->ofTenant(Tenant::factory()->create())->room()->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::test(TakeOrder::class)->call('chooseLocation', $theirs->getKey());

    expect($page->get('locationId'))->toBeNull();
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

it('offers the picker in the same order the floor uses, busy first', function (): void {
    [$tenant] = counterFor();
    Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 101', 'position' => 1]);
    $busy = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 109', 'position' => 9]);

    Order::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'location_id' => $busy->getKey(),
        'status' => OrderStatus::Placed,
        'subtotal' => 10000,
        'tax' => 0,
        'cgst' => 0,
        'sgst' => 0,
        'charges_total' => 0,
        'total' => 10000,
    ]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $names = array_map(
        static fn (array $card): string => $card['location']->name,
        Livewire::test(TakeOrder::class)->instance()->locationCards(),
    );

    // Room 109 sorts last by position and first here, because something is
    // running there — which is the room staff are most likely adding to.
    expect($names)->toBe(['Room 109', 'Room 101']);
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
        ->call('chooseLocation', $room->getKey())
        ->mountAction(TestAction::make('viewOrderAction')->arguments(['order' => $running->getKey()]));

    $html = (string) collect($page->effects['partials'] ?? [])
        ->first(fn (mixed $partial, string $key): bool => str_starts_with($key, 'action-modals'));

    expect($html)->toContain('Order #'.$running->getKey())
        ->and($html)->toContain('Room 204');
});
