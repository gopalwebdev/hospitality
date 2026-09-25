<?php

namespace App\Actions\Orders;

use App\Enums\LocationActivity;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The floor as it is looked at: every place an order can go, with what is open
 * at each, **the ones needing attention first**.
 *
 * Two queries for the whole screen, however many rooms it draws — the locations
 * and ReadLocationActivity's one grouped read — because both the orders page's
 * floor layout and the counter's location picker draw this, and both poll or
 * re-render often.
 *
 * The order is the point, and it is the project owner's: a room with nothing
 * being worked is not what staff are looking for, so it goes last. Above it,
 * **the loudest first** — a room with something ready before one with an order
 * nobody has picked up, before one already in hand — which is
 * LocationActivity's own declared order and nothing this class decides for
 * itself. Rooms in the same state are read newest order first. Locations with
 * nothing underway keep the tenant's own order among themselves (PHP's sort is
 * stable), so a quiet floor reads Room 101, 102, 103 rather than shuffling on
 * every poll.
 *
 * Filtering — by kind, by a search, to only what is open — is left to the page
 * asking, over the list this returns. It is already in memory, and the two
 * pages narrow it differently.
 *
 * @phpstan-import-type Activity from ReadLocationActivity
 *
 * @phpstan-type FloorCard array{location: Location, activity: Activity}
 */
final readonly class ReadFloor
{
    public function __construct(private ReadLocationActivity $readLocationActivity) {}

    /**
     * @return list<FloorCard>
     */
    public function __invoke(Tenant $tenant): array
    {
        $activity = ($this->readLocationActivity)($tenant);

        $cards = $this->locations($tenant)
            ->map(fn (Location $location): array => [
                'location' => $location,
                'activity' => $activity[$location->getKey()] ?? ReadLocationActivity::clear(),
            ])
            ->all();

        $urgency = array_flip(array_map(
            static fn (LocationActivity $state): string => $state->value,
            LocationActivity::cases(),
        ));

        usort($cards, function (array $first, array $second) use ($urgency): int {
            $firstRank = $urgency[$first['activity']['state']->value];
            $secondRank = $urgency[$second['activity']['state']->value];

            if ($firstRank !== $secondRank) {
                return $firstRank <=> $secondRank;
            }

            // Nothing underway on either: leave them in the tenant's own
            // order. PHP's sort is stable, so returning 0 really does keep it.
            return $first['activity']['state']->isOpen()
                ? $second['activity']['lastOrderedAt'] <=> $first['activity']['lastOrderedAt']
                : 0;
        });

        return $cards;
    }

    /**
     * Every location this tenant offers.
     *
     * Switched-off ones are left out: this is where an order goes next, and
     * one nobody may be sent to is not a card.
     *
     * @return EloquentCollection<int, Location>
     */
    private function locations(Tenant $tenant): EloquentCollection
    {
        return Location::query()
            ->select(['id', 'name', 'kind', 'code', 'capacity'])
            ->where('tenant_id', $tenant->getKey())
            ->active()
            ->inReadingOrder()
            ->get();
    }
}
