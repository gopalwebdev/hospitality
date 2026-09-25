<?php

namespace App\Actions\Orders;

use App\Enums\LocationActivity;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * What is being worked at each of a tenant's locations: how many orders are
 * waiting, how many are being made, and how many are ready to be taken over.
 *
 * **No money.** The project owner's instruction for the floor: a card says
 * where the work is, and what a room owes is the list layout's business and the
 * Locations page's Settle. So a served order leaves this read even though it
 * may not have been paid for, and nothing here sums an amount.
 *
 * One grouped query for the whole screen, however many rooms or tables it
 * draws, because the floor polls this every few seconds — a read per card would
 * be a query per card per tick. Only locations with something underway come
 * back; everywhere else is LocationActivity::Clear and needs no row.
 *
 * @phpstan-type Activity array{pending: int, preparing: int, ready: int, orders: int, lastOrderedAt: CarbonImmutable|null, state: LocationActivity}
 */
final readonly class ReadLocationActivity
{
    /**
     * Keyed by location id, and missing the locations with nothing underway.
     *
     * @return array<int, Activity>
     */
    public function __invoke(Tenant $tenant): array
    {
        $rows = Order::query()
            ->where('orders.tenant_id', $tenant->getKey())
            ->whereIn('orders.status', OrderStatus::underwayValues())
            ->whereNotNull('orders.location_id')
            ->groupBy('orders.location_id', 'orders.status')
            ->selectRaw('orders.location_id')
            ->selectRaw('orders.status')
            ->selectRaw('count(*) as orders')
            ->selectRaw('max(orders.created_at) as last_ordered_at')
            // Rows rather than models: this is an aggregate over orders, and
            // hydrating an Order per location would claim to be an order.
            ->toBase()
            ->get();

        /** @var array<int, array{pending: int, preparing: int, ready: int, lastOrderedAt: CarbonImmutable|null}> $tally */
        $tally = [];

        foreach ($rows as $row) {
            $locationId = (int) $row->location_id;
            $status = OrderStatus::from((string) $row->status);
            $count = (int) $row->orders;
            $landedAt = is_string($row->last_ordered_at) ? CarbonImmutable::parse($row->last_ordered_at) : null;

            $own = $tally[$locationId] ?? ['pending' => 0, 'preparing' => 0, 'ready' => 0, 'lastOrderedAt' => null];

            // Named one by one rather than through a lookup: three counters is
            // few enough to read, and the shape stays something a reader — and
            // static analysis — can follow.
            $own['pending'] += $status === OrderStatus::Placed ? $count : 0;
            $own['preparing'] += $status === OrderStatus::Accepted ? $count : 0;
            $own['ready'] += $status === OrderStatus::Ready ? $count : 0;

            if ($landedAt instanceof CarbonImmutable && ($own['lastOrderedAt'] === null || $landedAt->greaterThan($own['lastOrderedAt']))) {
                $own['lastOrderedAt'] = $landedAt;
            }

            $tally[$locationId] = $own;
        }

        $activity = [];

        foreach ($tally as $locationId => $own) {
            $activity[$locationId] = [
                'pending' => $own['pending'],
                'preparing' => $own['preparing'],
                'ready' => $own['ready'],
                'orders' => $own['pending'] + $own['preparing'] + $own['ready'],
                'lastOrderedAt' => $own['lastOrderedAt'],
                // The loudest of whatever is actually here. The ranking is
                // LocationActivity's own declared order, not this class's.
                'state' => LocationActivity::mostUrgent(...array_values(array_filter([
                    $own['ready'] > 0 ? LocationActivity::Ready : null,
                    $own['pending'] > 0 ? LocationActivity::Pending : null,
                    $own['preparing'] > 0 ? LocationActivity::Preparing : null,
                ]))),
            ];
        }

        return $activity;
    }

    /**
     * What a location with nothing underway reads as — so a card never has to
     * ask whether it has a row.
     *
     * @return Activity
     */
    public static function clear(): array
    {
        return [
            'pending' => 0,
            'preparing' => 0,
            'ready' => 0,
            'orders' => 0,
            'lastOrderedAt' => null,
            'state' => LocationActivity::Clear,
        ];
    }
}
