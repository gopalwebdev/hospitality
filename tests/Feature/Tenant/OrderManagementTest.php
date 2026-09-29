<?php

use App\Actions\Baskets\PriceBasket;
use App\Actions\Orders\PlaceOrder;
use App\Enums\ItemAvailability;
use App\Enums\Locale;
use App\Enums\OrderStatus;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\Orders\Pages\ListOrders;
use App\Models\Location;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Orders in the tenant panel
|--------------------------------------------------------------------------
|
| Guests place orders; the panel lists them, opens one, and cancels one —
| which puts back what it took from stock.
|
*/

/**
 * The HTML of the modal a just-mounted action opened.
 *
 * A Filament modal comes back as a partial of its own and the component's
 * html() holds none of it (`.ai/rules/filament.md`).
 */
function mountedModalHtml(Testable $page): string
{
    return (string) collect($page->effects['partials'] ?? [])
        ->first(fn (mixed $partial, string $key): bool => str_starts_with($key, 'action-modals'));
}

/**
 * A counted item on a fresh menu of the tenant given.
 *
 * @param  array<string, mixed>  $attributes
 */
function orderableItemFor(Tenant $tenant, int $stock, array $attributes = []): MenuItem
{
    return MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))->create())
        ->stocked($stock)
        ->create($attributes);
}

/**
 * An order for some of an item, placed the way a guest places one.
 */
function placedOrderOf(MenuItem $item, int $quantity): Order
{
    $category = MenuCategory::query()->withoutGlobalScopes()->findOrFail($item->menu_category_id);

    return app(PlaceOrder::class)(
        Tenant::query()->findOrFail($item->tenant_id),
        Menu::query()->withoutGlobalScopes()->findOrFail($category->menu_id),
        [['key' => 'item-'.$item->getKey(), 'type' => PriceBasket::ITEM, 'id' => $item->getKey(), 'quantity' => $quantity, 'choices' => []]],
        locationLabel: 'Room 204',
    );
}

it('lists this tenant\'s orders for staff, newest first', function (): void {
    $tenant = Tenant::factory()->create();
    $item = orderableItemFor($tenant, stock: 10);
    $first = placedOrderOf($item, 1);
    $second = placedOrderOf($item, 2);
    $theirs = Order::factory()->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(ListOrders::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$second, $first], inOrder: true)
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('opens an order with what was ordered and what it came to, under the name it was ordered by', function (): void {
    $tenant = Tenant::factory()->create();
    taxTenantAt($tenant, 500);

    $item = orderableItemFor($tenant, stock: 10, attributes: [
        'name' => [Locale::English->value => 'Paneer Tikka'],
        'price' => 24900,
    ]);
    $order = placedOrderOf($item, 2);

    // Renamed since: the order keeps the name it was placed under.
    $item->update(['name' => [Locale::English->value => 'Tandoori Platter']]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // Two at ₹249.00 is ₹498.00, and 5% GST on top is ₹522.90. Read in a
    // modal over the list: there is no order page (OrdersTable::viewAction()).
    $html = mountedModalHtml(
        Livewire::test(ListOrders::class)->mountAction(TestAction::make('view')->table($order)),
    );

    expect($html)->toContain('Paneer Tikka')
        ->and($html)->not->toContain('Tandoori Platter')
        ->and($html)->toContain('Room 204')
        ->and($html)->toContain('₹522.90');
});

it('cancels an order from the list, putting back what it took', function (): void {
    $tenant = Tenant::factory()->create();
    $item = orderableItemFor($tenant, stock: 2);
    $order = placedOrderOf($item, 2);

    expect($item->refresh()->availability)->toBe(ItemAvailability::OutOfStock);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(ListOrders::class)
        ->callAction(TestAction::make('cancel')->table($order))
        ->assertHasNoActionErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($item->refresh()->stock_quantity)->toBe(2)
        ->and($item->availability)->toBe(ItemAvailability::Available);
});

it('reads an order in a modal with its payments loaded, not lazily while it renders', function (): void {
    $tenant = Tenant::factory()->create();
    $item = orderableItemFor($tenant, stock: 2);
    $order = placedOrderOf($item, 2);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // The list query carries no lines, choices, charges or payments, so the
    // modal has to load them as it mounts (OrderResource::loadForView()).
    // Rendering the infolist without that lazy-loads under strict mode, which
    // throws — so simply opening the modal is the assertion.
    $html = mountedModalHtml(
        Livewire::test(ListOrders::class)->mountAction(TestAction::make('view')->table($order)),
    );

    expect($html)->toContain('Nothing has been paid on this order yet.');
});

it('offers no cancel on an order already cancelled', function (): void {
    $tenant = Tenant::factory()->create();
    $cancelled = Order::factory()
        ->onMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->cancelled()
        ->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(ListOrders::class)
        ->assertActionHidden(TestAction::make('cancel')->table($cancelled));
});

it('keeps orders from an account that may not see them', function (): void {
    enterTenantPanel(Tenant::factory()->create(), RoleEnum::Guest);

    Livewire::test(ListOrders::class)->assertForbidden();
});

it('lists orders in the same number of queries however many there are', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $addOrder = function () use ($menu): void {
        OrderLine::factory()->count(2)->inOrder(Order::factory()->onMenu($menu)->create())->create();
    };

    $queriesToRender = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(ListOrders::class)->assertOk();

        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $addOrder();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The first render warms what a request caches, the permissions among them.
    $queriesToRender();
    $one = $queriesToRender();

    $addOrder();
    $addOrder();
    $addOrder();

    expect($queriesToRender())->toBe($one);
});

/*
|--------------------------------------------------------------------------
| Reading one order
|--------------------------------------------------------------------------
*/

it('reads an order as a receipt, with the money down the right', function (): void {
    $tenant = Tenant::factory()->create();
    taxTenantAt($tenant, 1800);
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $item = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->stocked(10)
        ->create(['name' => ['en' => 'Masala Dosa'], 'price' => 12000]);

    $order = app(PlaceOrder::class)($tenant, $menu, [[
        'key' => 'item:'.$item->getKey(),
        'type' => PriceBasket::ITEM,
        'id' => $item->getKey(),
        'quantity' => 2,
        'choices' => [],
    ]]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $html = mountedModalHtml(
        Livewire::test(ListOrders::class)->mountAction(TestAction::make('view')->table($order)),
    );

    // The lines take two columns of three and the totals the third, so the
    // money reads down the right the way it does on a bill rather than
    // spread across four columns underneath. The project owner asked for it.
    expect($html)->toContain('--col-span-clg: span 2 / span 2')
        // Inline labels are what make the totals read as a receipt foot
        // rather than as four more fields.
        ->and($html)->toContain('fi-in-entry-has-inline-label')
        ->and($html)->toContain('Masala Dosa')
        ->and($html)->toContain('Subtotal')
        // What is still owed sits with the money, and is no longer said a
        // second time under the payments.
        ->and(substr_count($html, 'Outstanding'))->toBe(1);
});

it('names an order by the tenant\'s own count, not by its row id', function (): void {
    $tenant = Tenant::factory()->create();
    taxTenantAt($tenant, 0);
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $item = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->stocked(10)
        ->create(['price' => 10000]);

    $order = app(PlaceOrder::class)($tenant, $menu, [[
        'key' => 'item:'.$item->getKey(),
        'type' => PriceBasket::ITEM,
        'id' => $item->getKey(),
        'quantity' => 1,
        'choices' => [],
    ]]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    expect($order->reference())->toBe('#001');

    Livewire::test(ListOrders::class)
        ->assertSee('#001')
        // Searched on the number staff are told over the phone.
        ->searchTable('1')
        ->assertCanSeeTableRecords([$order]);
});

/*
|--------------------------------------------------------------------------
| Filtering the list
|--------------------------------------------------------------------------
|
| Three controls staff reach for — when, what state, and where — above the
| rows rather than behind the filter button, because the list opens already
| filtered to today and a default nobody can see is a list quietly missing
| rows.
|
*/

it('opens showing today, and shows everything once the dates are cleared', function (): void {
    $tenant = Tenant::factory()->create();
    taxTenantAt($tenant, 0);

    $today = Order::factory()->create(['tenant_id' => $tenant->getKey()]);
    $lastWeek = Order::factory()->create(['tenant_id' => $tenant->getKey()]);
    $lastWeek->forceFill(['created_at' => Date::now()->subWeek()])->save();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$lastWeek])
        // Defaulted rather than written into the query, so clearing the two
        // dates really does show everything — a default in the query would
        // be a floor nobody could get under.
        ->set('tableFilters.placed_between.from', null)
        ->set('tableFilters.placed_between.until', null)
        ->assertCanSeeTableRecords([$today, $lastWeek]);
});

it('filters by a range of days, by status and by place', function (): void {
    $tenant = Tenant::factory()->create();
    taxTenantAt($tenant, 0);

    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    $pool = Location::factory()->ofTenant($tenant)->area()->create(['name' => 'Poolside']);

    $inRoom = Order::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'location_id' => $room->getKey(),
        'status' => OrderStatus::Ready,
    ]);

    $atPool = Order::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'location_id' => $pool->getKey(),
        'status' => OrderStatus::Placed,
    ]);

    $older = Order::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'location_id' => $room->getKey(),
        'status' => OrderStatus::Ready,
    ]);
    $older->forceFill(['created_at' => Date::now()->subDays(3)])->save();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListOrders::class)
        // A range, not just one day.
        ->set('tableFilters.placed_between.from', Date::now()->subDays(4)->toDateString())
        ->assertCanSeeTableRecords([$inRoom, $atPool, $older])
        // Where: several places at once, so the key is `values`.
        ->set('tableFilters.location_id.values', [(string) $room->getKey()])
        ->assertCanSeeTableRecords([$inRoom, $older])
        ->assertCanNotSeeTableRecords([$atPool])
        // What state: several at once too.
        ->set('tableFilters.status.values', [OrderStatus::Placed->value])
        ->assertCanNotSeeTableRecords([$inRoom, $older]);
});
