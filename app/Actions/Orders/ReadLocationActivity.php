<?php

namespace App\Actions\Orders;

use App\Enums\LocationActivity;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * What is live at each of a tenant's locations: how many orders are open there,
 * what they still owe, and when the newest of them landed.
 *
 * One grouped query for the whole board, however many rooms or tables it draws,
 * because the orders page's floor polls this every few seconds — a read per card would
 * be a query per card per tick. Only locations with something open come back;
 * everywhere else is LocationActivity::Clear and needs no row.
 *
 * "Open" is a placed order that still owes money, which is the same pair of
 * conditions LocationsTable's Settle action offers orders on: a cancelled order
 * is not activity, and one already settled is finished business.
 *
 * @phpstan-type Activity array{openOrders: int, outstanding: int, lastOrderedAt: CarbonImmutable|null, state: LocationActivity}
 */
final readonly class ReadLocationActivity
{
    /**
     * How recently an order has to have landed for its location to still read as new.
     */
    public const int NEW_ORDER_MINUTES = 10;

    /**
     * Keyed by location id, and missing the locations with nothing open.
     *
     * @return array<int, Activity>
     */
    public function __invoke(Tenant $tenant): array
    {
        $paid = Order::amountPaidExpression();
        $now = Date::now();
        $newSince = $now->subMinutes(self::NEW_ORDER_MINUTES);

        $rows = Order::query()
            ->where('orders.tenant_id', $tenant->getKey())
            ->whereIn('orders.status', OrderStatus::liveValues())
            ->whereNotNull('orders.location_id')
            ->unsettled()
            ->groupBy('orders.location_id')
            ->selectRaw('orders.location_id')
            ->selectRaw('count(*) as open_orders')
            ->selectRaw("sum(orders.total - {$paid}) as outstanding")
            ->selectRaw('max(orders.created_at) as last_ordered_at')
            // Rows rather than models: this is an aggregate over orders, and
            // hydrating an Order per location would claim to be an order.
            ->toBase()
            ->get();

        $activity = [];

        foreach ($rows as $row) {
            $lastOrderedAt = is_string($row->last_ordered_at)
                ? CarbonImmutable::parse($row->last_ordered_at)
                : null;

            $activity[(int) $row->location_id] = [
                'openOrders' => (int) $row->open_orders,
                'outstanding' => (int) $row->outstanding,
                'lastOrderedAt' => $lastOrderedAt,
                'state' => $lastOrderedAt instanceof CarbonImmutable && $lastOrderedAt->greaterThanOrEqualTo($newSince)
                    ? LocationActivity::JustOrdered
                    : LocationActivity::Running,
            ];
        }

        return $activity;
    }

    /**
     * What a location with nothing open reads as — so a card never has to ask
     * whether it has a row.
     *
     * @return Activity
     */
    public static function clear(): array
    {
        return [
            'openOrders' => 0,
            'outstanding' => 0,
            'lastOrderedAt' => null,
            'state' => LocationActivity::Clear,
        ];
    }
}
