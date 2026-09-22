<?php

use App\Enums\OrderStatus;
use App\Enums\StockMovementReason;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\StockMovement;
use App\Models\Tenant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OrderSeeder;

/*
|--------------------------------------------------------------------------
| OrderSeeder
|--------------------------------------------------------------------------
|
| TenantSeeder alone leaves orders, order lines, order charges and stock
| movements empty — nothing exercised the GST split, the HSN/SAC a line
| copies from its item, or any StockMovementReason but the ones a form
| writes by hand. These pin the shape OrderSeeder produces, not the
| arithmetic itself: orders_tax_parts_add_up and
| order_lines_tax_rates_add_up already refuse a row whose split does not
| add up, so a bad split would fail the seed outright rather than pass a
| test silently.
|
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('places a spread of orders against every seeded tenant, placed and cancelled alike', function (): void {
    foreach (Tenant::query()->get() as $tenant) {
        $orders = Order::query()->where('tenant_id', $tenant->getKey())->get();

        expect($orders)->not->toBeEmpty();
    }

    expect(Order::query()->where('status', OrderStatus::Placed)->count())->toBeGreaterThan(0)
        ->and(Order::query()->where('status', OrderStatus::Cancelled)->count())->toBeGreaterThan(0)
        // Cancelled together with cancelled_at is orders_cancelled_at_matches_status's
        // own job to refuse; this is the seeded data actually taking that path.
        ->and(Order::query()->where('status', OrderStatus::Cancelled)->whereNull('cancelled_at')->exists())->toBeFalse();
});

it('splits every seeded order into equal halves, and names the state\'s half where it sits', function (): void {
    $state = Tenant::query()->where('slug', 'spice')->sole();
    $unionTerritory = Tenant::query()->where('slug', 'seaview')->sole();

    $stateOrder = Order::query()->where('tenant_id', $state->getKey())->where('tax', '>', 0)->firstOrFail();
    $unionTerritoryOrder = Order::query()->where('tenant_id', $unionTerritory->getKey())->where('tax', '>', 0)->firstOrFail();

    expect($stateOrder->cgst)->toBeGreaterThan(0)
        ->and($stateOrder->sgst)->toBeGreaterThan(0)
        ->and($stateOrder->cgst + $stateOrder->sgst)->toBe($stateOrder->tax)
        // Puducherry: the same money, invoiced as UTGST.
        ->and($stateOrder->is_union_territory)->toBeFalse()
        ->and($unionTerritoryOrder->is_union_territory)->toBeTrue()
        ->and($unionTerritoryOrder->cgst + $unionTerritoryOrder->sgst)->toBe($unionTerritoryOrder->tax);
});

it('copies an item\'s HSN or SAC code onto the order line that ordered it', function (): void {
    $tenant = Tenant::query()->where('slug', 'seaview')->sole();

    // Water Bottle (1 L) carries an HSN code (App\Enums\ItemAvailability
    // aside, it is a sealed good, not a served drink) — see TenantSeeder::ROOM_REQUESTS.
    $line = OrderLine::query()
        ->where('tenant_id', $tenant->getKey())
        ->where('name->en', 'Water Bottle (1 L)')
        ->firstOrFail();

    expect($line->hsn_sac_code)->toBe('2201');
});

it('records all four stock movement reasons across the seeded tenants', function (): void {
    $reasons = StockMovement::query()->distinct()->pluck('reason')->map(fn (StockMovementReason $reason): string => $reason->value)->sort()->values()->all();

    expect($reasons)->toBe([
        StockMovementReason::Count->value,
        StockMovementReason::OrderCancelled->value,
        StockMovementReason::OrderPlaced->value,
        StockMovementReason::Restock->value,
    ]);
});

it('does not duplicate a tenant\'s orders when seeded again', function (): void {
    $countedBefore = Order::query()->count();
    $linesBefore = OrderLine::query()->count();
    $movementsBefore = StockMovement::query()->count();

    $this->seed(OrderSeeder::class);

    expect(Order::query()->count())->toBe($countedBefore)
        ->and(OrderLine::query()->count())->toBe($linesBefore)
        ->and(StockMovement::query()->count())->toBe($movementsBefore);
});
