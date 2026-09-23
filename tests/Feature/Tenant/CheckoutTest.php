<?php

use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\SpreadAcrossOrders;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\Locations\Pages\ListLocations;
use App\Models\Location;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
|
| Settling a location's whole running bill with one payment: the guest who
| added four room-service orders to their room pays once on the way out, and
| the panel records one transaction spread across the orders it cleared.
|
*/

/**
 * A placed order of the given tenant, at a location, owing exactly this much.
 */
function orderAtLocation(Tenant $tenant, ?Location $location, int $total): Order
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

it('spreads an amount across orders oldest first', function (): void {
    $tenant = Tenant::factory()->create();
    $first = orderAtLocation($tenant, null, 50000);
    $second = orderAtLocation($tenant, null, 30000);

    $allocations = app(SpreadAcrossOrders::class)(80000, [$first, $second]);

    expect($allocations)->toBe([$first->getKey() => 50000, $second->getKey() => 30000]);
});

it('gives the last order only what is left when the amount runs out', function (): void {
    $tenant = Tenant::factory()->create();
    $first = orderAtLocation($tenant, null, 50000);
    $second = orderAtLocation($tenant, null, 30000);
    $third = orderAtLocation($tenant, null, 20000);

    // A part payment: the earliest orders are cleared in full and the one the
    // money runs out on takes the remainder. Nothing is ever over-allocated.
    $allocations = app(SpreadAcrossOrders::class)(65000, [$first, $second, $third]);

    expect($allocations)->toBe([$first->getKey() => 50000, $second->getKey() => 15000])
        ->and(array_sum($allocations))->toBe(65000);
});

it('skips an order that owes nothing and stops when the money is gone', function (): void {
    $tenant = Tenant::factory()->create();
    $settled = orderAtLocation($tenant, null, 40000);
    $owing = orderAtLocation($tenant, null, 25000);

    app(RecordPayment::class)($tenant, PaymentMethod::Cash, 40000, [$settled->getKey() => 40000]);

    $allocations = app(SpreadAcrossOrders::class)(25000, [$settled->fresh(), $owing]);

    expect($allocations)->toBe([$owing->getKey() => 25000]);
});

it('settles a whole room with one payment from the panel', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create();
    $first = orderAtLocation($tenant, $room, 90000);
    $second = orderAtLocation($tenant, $room, 60000);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListLocations::class)
        ->callAction(
            TestAction::make('settle')->table($room),
            [
                'orders' => [$first->getKey(), $second->getKey()],
                'method' => PaymentMethod::Cash->value,
                'amount' => 1500,
            ],
        )
        ->assertHasNoActionErrors();

    // One transaction, two orders cleared: that is the whole point of
    // order_payments carrying an amount rather than a payment naming one order.
    $payment = Payment::query()->withoutGlobalScopes()->sole();

    expect($payment->amount)->toBe(150000)
        ->and($payment->allocations()->count())->toBe(2)
        ->and($first->fresh()?->paymentState())->toBe(PaymentState::Paid)
        ->and($second->fresh()?->paymentState())->toBe(PaymentState::Paid);
});

it('offers no settling on a location that owes nothing', function (): void {
    $tenant = Tenant::factory()->create();
    $room = Location::factory()->ofTenant($tenant)->room()->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListLocations::class)
        ->assertActionHidden(TestAction::make('settle')->table($room));
});
