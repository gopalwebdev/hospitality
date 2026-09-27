<?php

use App\Actions\Orders\NextOrderNumber;
use App\Actions\Orders\PlaceOrder;
use App\Actions\Orders\ReviseOrder;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\Date;

/*
|--------------------------------------------------------------------------
| What a tenant calls its orders
|--------------------------------------------------------------------------
|
| `orders.id` is global to the platform: a tenant's first ever order is #1 and
| the next tenant's is #4,062, which is not a number anybody reads back over a
| phone. So a tenant counts its own orders from 1 again each day, and
| (number, numbered_on) is what identifies one to the business — the id is
| still the key, and nothing looks an order up by its number.
|
*/

/**
 * A tenant with one thing on one menu, ready to be ordered.
 *
 * @return array{0: Tenant, 1: Menu, 2: MenuItem}
 */
function numberedSetup(): array
{
    $tenant = Tenant::factory()->create();
    taxTenantAt($tenant, 0);

    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $item = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->create(['price' => 10000]);

    return [$tenant, $menu, $item];
}

/**
 * @return list<array{key: string, type: string, id: int, quantity: int, choices: list<array{optionId: int, quantity: int}>}>
 */
function oneOf(MenuItem $item): array
{
    return [['key' => 'item:'.$item->getKey(), 'type' => 'item', 'id' => $item->getKey(), 'quantity' => 1, 'choices' => []]];
}

it('counts a tenant\'s orders from one, whatever their ids are', function (): void {
    [$tenant, $menu, $item] = numberedSetup();

    // Another tenant's orders first, so the ids are well past 1.
    [$theirs, $theirMenu, $theirItem] = numberedSetup();
    app(PlaceOrder::class)($theirs, $theirMenu, oneOf($theirItem));
    app(PlaceOrder::class)($theirs, $theirMenu, oneOf($theirItem));

    $first = app(PlaceOrder::class)($tenant, $menu, oneOf($item));
    $second = app(PlaceOrder::class)($tenant, $menu, oneOf($item));

    expect($first->number)->toBe(1)
        ->and($second->number)->toBe(2)
        // The id kept climbing past the other tenant's; the number did not.
        ->and($first->getKey())->toBeGreaterThan(2);
});

it('starts again at one the next day', function (): void {
    [$tenant, $menu, $item] = numberedSetup();

    Date::setTestNow(Date::parse('2026-09-27 21:00:00'));
    $lastNight = app(PlaceOrder::class)($tenant, $menu, oneOf($item));

    Date::setTestNow(Date::parse('2026-09-28 08:00:00'));
    $thisMorning = app(PlaceOrder::class)($tenant, $menu, oneOf($item));

    expect($lastNight->number)->toBe(1)
        ->and($thisMorning->number)->toBe(1)
        // Which is why the day is stored beside the count: on its own the
        // number names two different orders.
        ->and($lastNight->numbered_on->toDateString())->toBe('2026-09-27')
        ->and($thisMorning->numbered_on->toDateString())->toBe('2026-09-28');

    Date::setTestNow();
});

it('reads as a padded number nobody has to spell out', function (): void {
    [$tenant, $menu, $item] = numberedSetup();

    $order = app(PlaceOrder::class)($tenant, $menu, oneOf($item));

    expect($order->reference())->toBe('#001');

    $order->forceFill(['number' => 137])->save();

    expect($order->reference())->toBe('#137');
});

it('keeps its number when the order is changed', function (): void {
    [$tenant, $menu, $item] = numberedSetup();

    $order = app(PlaceOrder::class)($tenant, $menu, oneOf($item));
    $numberWas = $order->number;

    app(ReviseOrder::class)(
        tenant: $tenant,
        order: $order,
        menu: $menu,
        lines: [['key' => 'item:'.$item->getKey(), 'type' => 'item', 'id' => $item->getKey(), 'quantity' => 3, 'choices' => []]],
    );

    // ReviseOrder replaces what is on an order and keeps its number — the
    // guest was told one, and it is the same order.
    expect($order->refresh()->number)->toBe($numberWas)
        ->and(Order::query()->count())->toBe(1);
});

it('hands the same day two different numbers, never one twice', function (): void {
    [$tenant] = numberedSetup();

    // NextOrderNumber only reads; what stops a collision is the lock it takes
    // and the transaction PlaceOrder wraps it in, so this asserts the count
    // it arrives at rather than the concurrency.
    expect(app(NextOrderNumber::class)($tenant)['number'])->toBe(1);

    Order::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'number' => 7,
        'numbered_on' => Date::now()->startOfDay(),
    ]);

    expect(app(NextOrderNumber::class)($tenant)['number'])->toBe(8);
});
