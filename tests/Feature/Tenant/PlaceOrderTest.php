<?php

use App\Actions\Menus\QuoteBasket;
use App\Actions\Orders\CancelOrder;
use App\Enums\ItemAvailability;
use App\Enums\OrderLineType;
use App\Enums\OrderRefusal;
use App\Enums\OrderStatus;
use App\Enums\StockMovementReason;
use App\Enums\Weekday;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuComboItem;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\TenantOpeningHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Placing an order, and what it takes from stock
|--------------------------------------------------------------------------
|
| A guest's basket is priced again, and then placed in one transaction that
| takes from every counted item and option under a lock. Running short of
| anything refuses the whole order, says what is left, and takes nothing.
|
*/

function placeOrderUrl(Tenant $tenant, Menu $menu): string
{
    return 'http://'.$tenant->slug.'.hospitality.test/menus/'.$menu->getKey().'/orders';
}

/**
 * Five counted curries with a required bread nobody counts and four counted cheese, on a tenant adding 5% GST at the bill.
 *
 * @return array{tenant: Tenant, menu: Menu, category: MenuCategory, curry: MenuItem, butterNaan: MenuAddOnOption, cheese: MenuAddOnOption}
 */
function seedCountedCurry(): array
{
    $tenant = Tenant::factory()->create();
    taxTenantAt($tenant, 500);

    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $curry = MenuItem::factory()->inCategory($category)->translated()->stocked(5)->create(['price' => 28900]);

    $bread = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: true, max: 1)->create();
    $extras = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: false, max: 3)->create();

    MenuItemAddOnGroup::factory()->linking($curry, $bread)->create(['position' => 0]);
    MenuItemAddOnGroup::factory()->linking($curry, $extras)->create(['position' => 1]);

    return [
        'tenant' => $tenant,
        'menu' => $menu,
        'category' => $category,
        'curry' => $curry,
        'butterNaan' => MenuAddOnOption::factory()->inGroup($bread)->free()->create(),
        'cheese' => MenuAddOnOption::factory()->inGroup($extras)->upTo(2)->stocked(4)->create(['price' => 4000]),
    ];
}

/**
 * One line of a basket, as the guest app sends it.
 *
 * @param  list<array{MenuAddOnOption, int}>  $choices  each option and how many of it
 * @return array<string, mixed>
 */
function orderLine(string $key, MenuItem|MenuCombo $thing, int $quantity = 1, array $choices = []): array
{
    return [
        'key' => $key,
        'type' => $thing instanceof MenuCombo ? QuoteBasket::COMBO : QuoteBasket::ITEM,
        'id' => $thing->getKey(),
        'quantity' => $quantity,
        'choices' => array_map(
            fn (array $choice): array => ['optionId' => $choice[0]->getKey(), 'quantity' => $choice[1]],
            $choices,
        ),
    ];
}

/**
 * What an order took from stock, oldest first: whose count, why, by how much, and what was left.
 *
 * @return list<array{0: string, 1: StockMovementReason, 2: int, 3: int}>
 */
function movementsOfOrder(Order $order): array
{
    return StockMovement::query()
        ->where('order_id', $order->getKey())
        ->orderBy('id')
        ->get()
        ->map(fn (StockMovement $movement): array => [
            $movement->menu_item_id !== null ? 'item-'.$movement->menu_item_id : 'option-'.$movement->menu_add_on_option_id,
            $movement->reason,
            $movement->quantity_change,
            $movement->quantity_after,
        ])
        ->all();
}

it('places an order, copying what was ordered and taking it from stock', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'curry' => $curry, 'butterNaan' => $butterNaan, 'cheese' => $cheese] = seedCountedCurry();

    $response = $this->postJson(placeOrderUrl($tenant, $menu), [
        'lines' => [orderLine('curry', $curry, quantity: 2, choices: [[$butterNaan, 1], [$cheese, 2]])],
        'locationLabel' => 'Room 204',
        'note' => 'Less oil',
    ])->assertCreated();

    $order = Order::query()->with(['lines.choices'])->findOrFail($response->json('orderId'));
    $line = $order->lines->sole();

    // ₹289.00 with a free naan and two ₹40.00 cheese, twice, and 5% GST on top.
    expect($response->json('total'))->toBe(77490)
        ->and($order->tenant_id)->toBe($tenant->getKey())
        ->and($order->menu_id)->toBe($menu->getKey())
        ->and($order->status)->toBe(OrderStatus::Placed)
        ->and([$order->location_label, $order->note])->toBe(['Room 204', 'Less oil'])
        ->and([$order->subtotal, $order->tax, $order->charges_total, $order->total])->toBe([73800, 3690, 0, 77490])
        ->and($line->type)->toBe(OrderLineType::Item)
        ->and($line->menu_item_id)->toBe($curry->getKey())
        // Every language the item had, not just the one the guest read.
        ->and($line->getTranslations('name'))->toBe($curry->getTranslations('name'))
        ->and([$line->quantity, $line->unit_price, $line->total, $line->tax_rate])->toBe([2, 36900, 73800, 500])
        ->and($line->choices->map(fn ($choice): array => [$choice->menu_add_on_option_id, $choice->quantity, $choice->price])->all())
        ->toBe([[$butterNaan->getKey(), 1, 0], [$cheese->getKey(), 2, 4000]])
        // Five curries less two, and four cheese less two on each curry.
        ->and($curry->refresh()->stock_quantity)->toBe(3)
        ->and($cheese->refresh()->stock_quantity)->toBe(0)
        // Nobody counts the naan, so nothing was taken from it.
        ->and($butterNaan->refresh()->stock_quantity)->toBeNull()
        ->and(movementsOfOrder($order))->toBe([
            ['item-'.$curry->getKey(), StockMovementReason::OrderPlaced, -2, 3],
            ['option-'.$cheese->getKey(), StockMovementReason::OrderPlaced, -4, 0],
        ]);
});

it('marks an item out of stock when an order takes the last of it, and refuses the next order for it', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'curry' => $curry, 'butterNaan' => $butterNaan] = seedCountedCurry();
    $curry->update(['stock_quantity' => 1]);

    $this->postJson(placeOrderUrl($tenant, $menu), ['lines' => [orderLine('curry', $curry, choices: [[$butterNaan, 1]])]])
        ->assertCreated();

    expect($curry->refresh()->stock_quantity)->toBe(0)
        ->and($curry->availability)->toBe(ItemAvailability::OutOfStock);

    // Sold out now, so the line no longer stands and nothing is written.
    $this->postJson(placeOrderUrl($tenant, $menu), ['lines' => [orderLine('curry', $curry, choices: [[$butterNaan, 1]])]])
        ->assertUnprocessable()
        ->assertJsonPath('reason', OrderRefusal::LinesChanged->value)
        ->assertJsonPath('lines.0.status', QuoteBasket::UNAVAILABLE);

    expect(Order::query()->count())->toBe(1);
});

it('refuses three when only one is left, says what is left, and takes nothing at all', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'category' => $category, 'curry' => $curry, 'butterNaan' => $butterNaan] = seedCountedCurry();
    $curry->update(['stock_quantity' => 1]);
    $dal = MenuItem::factory()->inCategory($category)->stocked(10)->create();

    $this->postJson(placeOrderUrl($tenant, $menu), ['lines' => [
        orderLine('curry', $curry, quantity: 3, choices: [[$butterNaan, 1]]),
        orderLine('dal', $dal, quantity: 2),
    ]])
        ->assertUnprocessable()
        ->assertJsonPath('reason', OrderRefusal::InsufficientStock->value)
        ->assertJsonPath('shortages', [
            ['type' => 'item', 'id' => $curry->getKey(), 'requested' => 3, 'available' => 1, 'lineKeys' => ['curry']],
        ]);

    // All or nothing: not even the dal there was enough of.
    expect(Order::query()->exists())->toBeFalse()
        ->and($curry->refresh()->stock_quantity)->toBe(1)
        ->and($dal->refresh()->stock_quantity)->toBe(10)
        ->and(StockMovement::query()->where('reason', StockMovementReason::OrderPlaced->value)->exists())->toBeFalse();
});

it('adds up one item across lines, and an option across every item it is on', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'curry' => $curry, 'butterNaan' => $butterNaan, 'cheese' => $cheese] = seedCountedCurry();
    $cheese->update(['stock_quantity' => 2]);

    // One cheese on each of three curries, over two lines.
    $this->postJson(placeOrderUrl($tenant, $menu), ['lines' => [
        orderLine('one', $curry, choices: [[$butterNaan, 1], [$cheese, 1]]),
        orderLine('two', $curry, quantity: 2, choices: [[$butterNaan, 1], [$cheese, 1]]),
    ]])
        ->assertUnprocessable()
        ->assertJsonPath('shortages', [
            ['type' => 'option', 'id' => $cheese->getKey(), 'requested' => 3, 'available' => 2, 'lineKeys' => ['one', 'two']],
        ]);
});

it('counts a combo against the items in it', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'curry' => $curry] = seedCountedCurry();
    $curry->update(['stock_quantity' => 3]);

    $meal = MenuCombo::factory()->onMenu($menu)->create();
    MenuComboItem::factory()->pairing($meal, $curry)->quantity(2)->create();

    // Two meals are four curries, and three are left.
    $this->postJson(placeOrderUrl($tenant, $menu), ['lines' => [orderLine('meal', $meal, quantity: 2)]])
        ->assertUnprocessable()
        ->assertJsonPath('shortages.0.requested', 4)
        ->assertJsonPath('shortages.0.available', 3)
        ->assertJsonPath('shortages.0.lineKeys', ['meal']);

    $this->postJson(placeOrderUrl($tenant, $menu), ['lines' => [orderLine('meal', $meal)]])
        ->assertCreated();

    expect($curry->refresh()->stock_quantity)->toBe(1)
        ->and(Order::query()->sole()->lines()->sole()->type)->toBe(OrderLineType::Combo);
});

it('refuses an order while the tenant is closed, and takes nothing', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'curry' => $curry, 'butterNaan' => $butterNaan] = seedCountedCurry();

    // Whatever day the suite runs on is this tenant's weekly holiday.
    TenantOpeningHour::factory()
        ->ofTenant($tenant)
        ->on(Weekday::on(CarbonImmutable::now()))
        ->closed()
        ->create();

    $this->postJson(placeOrderUrl($tenant, $menu), ['lines' => [orderLine('curry', $curry, choices: [[$butterNaan, 1]])]])
        ->assertUnprocessable()
        ->assertJsonPath('reason', OrderRefusal::StoreClosed->value);

    expect($curry->refresh()->stock_quantity)->toBe(5)
        ->and(Order::query()->exists())->toBeFalse();
});

it('refuses an order outside the menu\'s service window', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'curry' => $curry, 'butterNaan' => $butterNaan] = seedCountedCurry();
    $menu->forceFill(['available_from' => '07:00', 'available_until' => '11:00'])->save();

    $this->travelTo(CarbonImmutable::parse('2026-09-15 15:00'));

    $this->postJson(placeOrderUrl($tenant, $menu), ['lines' => [orderLine('curry', $curry, choices: [[$butterNaan, 1]])]])
        ->assertUnprocessable()
        ->assertJsonPath('reason', OrderRefusal::NotBeingServed->value);

    expect($curry->refresh()->stock_quantity)->toBe(5);
});

it('refuses an empty basket', function (): void {
    ['tenant' => $tenant, 'menu' => $menu] = seedCountedCurry();

    $this->postJson(placeOrderUrl($tenant, $menu), ['lines' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines']);
});

it('places an order in the same number of reads however many lines it has', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'category' => $category, 'curry' => $curry, 'butterNaan' => $butterNaan, 'cheese' => $cheese] = seedCountedCurry();
    $dal = MenuItem::factory()->inCategory($category)->stocked(20)->create();
    $rice = MenuItem::factory()->inCategory($category)->create();

    $readsToPlace = function (array $lines) use ($tenant, $menu): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->postJson(placeOrderUrl($tenant, $menu), ['lines' => $lines])->assertCreated();

        DB::disableQueryLog();

        // Every line is written, so the writes grow with it; the reads must not.
        return collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select'))
            ->count();
    };

    $one = $readsToPlace([orderLine('curry', $curry, choices: [[$butterNaan, 1], [$cheese, 1]])]);

    $three = $readsToPlace([
        orderLine('curry', $curry, choices: [[$butterNaan, 1], [$cheese, 1]]),
        orderLine('dal', $dal, quantity: 2),
        orderLine('rice', $rice),
    ]);

    expect($three)->toBe($one);
});

/*
|--------------------------------------------------------------------------
| Cancelling
|--------------------------------------------------------------------------
*/

it('puts back exactly what a cancelled order took, and brings a sold-out item back', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'curry' => $curry, 'butterNaan' => $butterNaan, 'cheese' => $cheese] = seedCountedCurry();
    $curry->update(['stock_quantity' => 2]);

    $order = Order::query()->findOrFail($this->postJson(placeOrderUrl($tenant, $menu), ['lines' => [
        orderLine('curry', $curry, quantity: 2, choices: [[$butterNaan, 1], [$cheese, 1]]),
    ]])->assertCreated()->json('orderId'));

    expect($curry->refresh()->availability)->toBe(ItemAvailability::OutOfStock);

    app(CancelOrder::class)($order);

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancelled_at)->not->toBeNull()
        ->and($curry->refresh()->stock_quantity)->toBe(2)
        ->and($curry->availability)->toBe(ItemAvailability::Available)
        ->and($cheese->refresh()->stock_quantity)->toBe(4)
        ->and(array_slice(movementsOfOrder($order), 2))->toBe([
            ['item-'.$curry->getKey(), StockMovementReason::OrderCancelled, 2, 2],
            ['option-'.$cheese->getKey(), StockMovementReason::OrderCancelled, 2, 4],
        ]);
});

it('leaves an item switched off for another reason off when its stock comes back', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'curry' => $curry, 'butterNaan' => $butterNaan] = seedCountedCurry();
    $curry->update(['stock_quantity' => 1]);

    $order = Order::query()->findOrFail($this->postJson(placeOrderUrl($tenant, $menu), ['lines' => [
        orderLine('curry', $curry, choices: [[$butterNaan, 1]]),
    ]])->assertCreated()->json('orderId'));

    $curry->refresh()->update(['availability' => ItemAvailability::TemporarilyUnavailable]);

    app(CancelOrder::class)($order);

    expect($curry->refresh()->stock_quantity)->toBe(1)
        ->and($curry->availability)->toBe(ItemAvailability::TemporarilyUnavailable);
});

it('puts back what a combo took, even after the combo has changed', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'curry' => $curry] = seedCountedCurry();

    $meal = MenuCombo::factory()->onMenu($menu)->create();
    $content = MenuComboItem::factory()->pairing($meal, $curry)->quantity(2)->create();

    $order = Order::query()->findOrFail($this->postJson(placeOrderUrl($tenant, $menu), ['lines' => [
        orderLine('meal', $meal),
    ]])->assertCreated()->json('orderId'));

    $content->update(['quantity' => 1]);

    app(CancelOrder::class)($order);

    expect($curry->refresh()->stock_quantity)->toBe(5);
});

it('cancels an order only once', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'curry' => $curry, 'butterNaan' => $butterNaan] = seedCountedCurry();

    $order = Order::query()->findOrFail($this->postJson(placeOrderUrl($tenant, $menu), ['lines' => [
        orderLine('curry', $curry, choices: [[$butterNaan, 1]]),
    ]])->assertCreated()->json('orderId'));

    app(CancelOrder::class)($order);

    expect(fn () => app(CancelOrder::class)($order))->toThrow(LogicException::class)
        ->and($curry->refresh()->stock_quantity)->toBe(5);
});
