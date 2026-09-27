<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The next number this tenant's orders are counted by today.
 *
 * A tenant counts its own orders from 1 again each day, so what staff read out
 * over the phone is "order twelve" rather than "order four thousand and
 * sixty-two" — `orders.id` is global to the platform and says nothing to the
 * business looking at it. The pair `(number, numbered_on)` is what identifies
 * an order to a tenant, because the count resets; `Order::reference()` is how
 * it is read.
 *
 * **It is serialised on the tenant's own row, not on an index.** No table here
 * carries a unique constraint (`.ai/rules/migrations.md`), so nothing in the
 * database would refuse two orders taking the same number — and two members of
 * staff pressing Place at once is the ordinary case at a counter, not a rare
 * one. Locking the tenant row makes the read-then-write a queue: the second
 * caller waits, then counts, and both are inside PlaceOrder's transaction
 * already. A number is never handed out twice for the same day.
 *
 * The day is `config('app.timezone')`'s, which is the only timezone there is
 * (`.ai/rules/app.md`) — a tenant's day rolls over at midnight where it trades,
 * not at UTC.
 */
final readonly class NextOrderNumber
{
    /**
     * @return array{number: int, numberedOn: CarbonImmutable}
     */
    public function __invoke(Tenant $tenant, ?CarbonImmutable $today = null): array
    {
        $today ??= Date::now()->startOfDay();

        // The queue. Nothing is read from the row: holding it is the point.
        DB::table('tenants')->where('id', $tenant->getKey())->lockForUpdate()->first();

        $highest = (int) Order::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereDate('numbered_on', $today)
            ->max('number');

        return ['number' => $highest + 1, 'numberedOn' => $today];
    }
}
