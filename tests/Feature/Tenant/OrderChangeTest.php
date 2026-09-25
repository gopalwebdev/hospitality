<?php

use App\Actions\Baskets\PriceBasket;
use App\Actions\Orders\AdvanceOrder;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\PlaceOrder;
use App\Actions\Orders\ReviseOrder;
use App\Actions\Payments\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role as RoleEnum;
use App\Enums\StockMovementReason;
use App\Exceptions\InsufficientStock;
use App\Filament\Tenant\Pages\TakeOrder;
use App\Filament\Tenant\Resources\Orders\Pages\ListOrders;
use App\Models\Location;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Accepting an order, and changing one before it is accepted
|--------------------------------------------------------------------------
|
| The project owner's rule: until the kitchen has picked an order up, staff
| may still change what is on it; once it is accepted they may not. What
| matters below is that the line is drawn in one place and that stock always
| adds up — a changed order gives back everything it was holding before it
| takes what it now asks for.
|
*/

/**
 * A tenant, a menu and one counted item on it.
 *
 * @return array{0: Tenant, 1: Menu, 2: MenuItem}
 */
function orderableSetup(int $stock = 20): array
{
    $tenant = Tenant::factory()->create();
    taxTenantAt($tenant, 500);

    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $item = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->stocked($stock)
        ->create(['name' => ['en' => 'Biryani'], 'price' => 20000]);

    return [$tenant, $menu, $item];
}

/**
 * A basket of one item, in PriceBasket's shape.
 *
 * @return list<array{key: string, type: string, id: int, quantity: int, choices: list<array{optionId: int, quantity: int}>}>
 */
function basketOf(MenuItem $item, int $quantity): array
{
    return [[
        'key' => 'item:'.$item->getKey(),
        'type' => PriceBasket::ITEM,
        'id' => $item->getKey(),
        'quantity' => $quantity,
        'choices' => [],
    ]];
}

it('moves an order one step at a time, and stops once it has been served', function (): void {
    [$tenant, $menu, $item] = orderableSetup();
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 1));

    expect(app(AdvanceOrder::class)($order))->toBe(OrderStatus::Accepted)
        ->and(app(AdvanceOrder::class)($order->refresh()))->toBe(OrderStatus::Ready)
        ->and(app(AdvanceOrder::class)($order->refresh()))->toBe(OrderStatus::Served)
        ->and($order->refresh()->status)->toBe(OrderStatus::Served);

    // The line ends there: a served order has nowhere further to go.
    app(AdvanceOrder::class)($order->refresh());
})->throws(LogicException::class, 'cannot be moved any further');

it('changes a placed order, re-pricing it and squaring the stock', function (): void {
    [$tenant, $menu, $item] = orderableSetup(stock: 20);
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 2));

    expect($item->refresh()->stock_quantity)->toBe(18)
        ->and($order->total)->toBe(42000);

    app(ReviseOrder::class)($tenant, $order, $menu, basketOf($item, 5));

    // Two went back and five came out: thirteen left, not eleven.
    expect($item->refresh()->stock_quantity)->toBe(15)
        ->and($order->refresh()->total)->toBe(105000)
        ->and($order->lines()->sum('quantity'))->toBe(5)
        // Still the same order, with its own number.
        ->and(Order::query()->count())->toBe(1);
});

it('leaves a changed order cancellable, putting back only what it still holds', function (): void {
    [$tenant, $menu, $item] = orderableSetup(stock: 20);
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 2));

    app(ReviseOrder::class)($tenant, $order, $menu, basketOf($item, 5));
    app(CancelOrder::class)($order->refresh());

    // The whole twenty, never twenty-two: cancelling nets the order's takes
    // against what changing it already handed back.
    expect($item->refresh()->stock_quantity)->toBe(20)
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('writes the give-back and the fresh take as their own movements', function (): void {
    [$tenant, $menu, $item] = orderableSetup();
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 2));

    app(ReviseOrder::class)($tenant, $order, $menu, basketOf($item, 3));

    $reasons = StockMovement::query()
        ->where('order_id', $order->getKey())
        ->orderBy('id')
        ->pluck('reason')
        ->all();

    expect($reasons)->toBe([
        StockMovementReason::OrderPlaced,
        StockMovementReason::OrderRevised,
        StockMovementReason::OrderPlaced,
    ]);
});

it('refuses to change an order the kitchen has accepted', function (): void {
    [$tenant, $menu, $item] = orderableSetup();
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 1));

    app(AdvanceOrder::class)($order);

    app(ReviseOrder::class)($tenant, $order->refresh(), $menu, basketOf($item, 3));
})->throws(LogicException::class, 'picked up already');

it('refuses to change an order a payment stands against', function (): void {
    [$tenant, $menu, $item] = orderableSetup();
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 1));

    app(RecordPayment::class)($tenant, PaymentMethod::Cash, 1000, [$order->getKey() => 1000]);

    app(ReviseOrder::class)($tenant, $order->refresh(), $menu, basketOf($item, 3));
})->throws(LogicException::class, 'void it before');

it('leaves an order untouched when the change asks for more than is left', function (): void {
    [$tenant, $menu, $item] = orderableSetup(stock: 3);
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 2));

    try {
        app(ReviseOrder::class)($tenant, $order, $menu, basketOf($item, 9));
    } catch (InsufficientStock) {
        // The whole thing rolls back: the order still holds its own two.
    }

    expect($item->refresh()->stock_quantity)->toBe(1)
        ->and($order->refresh()->lines()->sum('quantity'))->toBe(2)
        ->and($order->total)->toBe(42000);
});

/*
|--------------------------------------------------------------------------
| In the panel
|--------------------------------------------------------------------------
*/

it('offers one button that moves an order along, and takes Change away once it does', function (): void {
    [$tenant, $menu, $item] = orderableSetup();
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 1));

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(ListOrders::class)
        ->assertActionVisible(TestAction::make('advance')->table($order))
        ->assertActionVisible(TestAction::make('change')->table($order))
        ->callAction(TestAction::make('advance')->table($order))
        ->assertHasNoActionErrors()
        // Still one step further to go, but no longer changeable.
        ->assertActionVisible(TestAction::make('advance')->table($order))
        ->assertActionHidden(TestAction::make('change')->table($order));

    expect($order->refresh()->status)->toBe(OrderStatus::Accepted);
});

it('offers nothing further on an order already served', function (): void {
    [$tenant, $menu, $item] = orderableSetup();
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 1));
    $order->update(['status' => OrderStatus::Served]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(ListOrders::class)
        ->assertActionHidden(TestAction::make('advance')->table($order))
        ->assertActionHidden(TestAction::make('change')->table($order))
        // Nothing to call off either: the guest has it.
        ->assertActionHidden(TestAction::make('cancel')->table($order));
});

it('withholds Change from an order a payment already stands against', function (): void {
    [$tenant, $menu, $item] = orderableSetup();
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 1));

    app(RecordPayment::class)($tenant, PaymentMethod::Cash, 1000, [$order->getKey() => 1000]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(ListOrders::class)
        ->assertActionHidden(TestAction::make('change')->table($order))
        // Still movable along, and still cancellable: only changing it is out.
        ->assertActionVisible(TestAction::make('advance')->table($order));
});

it('opens the counter with the order already in its basket, and saves the change', function (): void {
    [$tenant, $menu, $item] = orderableSetup();
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 2));

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::withQueryParams(['order' => $order->getKey()])->test(TakeOrder::class);

    expect($page->get('lines'))->toHaveCount(1)
        ->and($page->get('lines')[0]['quantity'])->toBe(2)
        ->and($page->instance()->isChangingAnOrder())->toBeTrue()
        // Never the picker: this one already knows where it is going.
        ->and($page->instance()->isPickingLocation())->toBeFalse();

    $page->assertSee('Changing order #'.$order->getKey())
        ->call('increment', 'item:'.$item->getKey())
        ->call('placeOrder')
        ->assertRedirect();

    expect($order->refresh()->lines()->sum('quantity'))->toBe(3)
        ->and(Order::query()->count())->toBe(1);
});

it('treats a link to an order it may no longer change as a new order', function (): void {
    [$tenant, $menu, $item] = orderableSetup();
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 1));

    app(AdvanceOrder::class)($order);

    enterTenantPanel($tenant, RoleEnum::Staff);

    $page = Livewire::withQueryParams(['order' => $order->getKey()])->test(TakeOrder::class);

    expect($page->instance()->isChangingAnOrder())->toBeFalse()
        ->and($page->get('lines'))->toBe([]);
});

it('moves one of the orders running at a room along from the counter itself', function (): void {
    [$tenant, $menu, $item] = orderableSetup();
    $room = Location::factory()->ofTenant($tenant)->room()->create(['name' => 'Room 204']);
    $order = app(PlaceOrder::class)($tenant, $menu, basketOf($item, 1), $room);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::withQueryParams(['location' => $room->getKey()])
        ->test(TakeOrder::class)
        ->assertSee('Already running here')
        ->callAction(TestAction::make('advanceOrderAction')->arguments(['order' => $order->getKey()]))
        ->assertHasNoActionErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Accepted);
});
