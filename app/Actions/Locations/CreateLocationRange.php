<?php

namespace App\Actions\Locations;

use App\Enums\Locale;
use App\Enums\LocationKind;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Create a numbered run of locations at once: "Room", 101 to 120 is twenty rooms.
 *
 * A hotel does not add its rooms one at a time. Names already taken by this
 * tenant are skipped rather than duplicated — compared on the English
 * fallback, the one every uniqueness rule in this application checks
 * (Model::fallbackLocalePath()) — so running the same range twice tops up
 * whatever is missing instead of erroring or doubling up.
 */
final readonly class CreateLocationRange
{
    /** The most locations one range may create at once — a guard against a typo in the range, not a real limit. */
    private const int MAX_RANGE = 500;

    /**
     * @return int how many locations were actually created
     *
     * @throws LogicException when the prefix is blank, the range runs backwards, or it is unreasonably large
     */
    public function __invoke(Tenant $tenant, LocationKind $kind, string $namePrefix, int $from, int $to): int
    {
        $namePrefix = trim($namePrefix);

        throw_if($namePrefix === '', LogicException::class, 'A range needs a name.');
        throw_unless($to >= $from, LogicException::class, 'A range must run from a smaller number to a larger one.');
        throw_if($to - $from + 1 > self::MAX_RANGE, LogicException::class, sprintf('A range may create at most %d locations at once.', self::MAX_RANGE));

        return DB::transaction(function () use ($tenant, $kind, $namePrefix, $from, $to): int {
            $wanted = [];

            for ($number = $from; $number <= $to; $number++) {
                $wanted[$number] = $namePrefix.' '.$number;
            }

            $existingNames = Location::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereIn(Location::fallbackLocalePath(), array_values($wanted))
                ->get(['name'])
                ->map(fn (Location $location): ?string => $location->getTranslations('name')[Locale::default()->value] ?? null)
                ->filter()
                ->all();

            $created = 0;

            foreach ($wanted as $name) {
                if (in_array($name, $existingNames, true)) {
                    continue;
                }

                $location = new Location([
                    'kind' => $kind,
                    'name' => [Locale::default()->value => $name],
                ]);

                $location->forceFill(['tenant_id' => $tenant->getKey()])->save();

                $created++;
            }

            return $created;
        });
    }
}
