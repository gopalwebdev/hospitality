<?php

namespace App\Exceptions;

use App\Actions\Inventory\StockChange;
use App\Enums\OrderRefusal;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An order asked for more than a counted item or option has left.
 *
 * Thrown under the lock and before anything is written, so the order it refuses
 * never happened. Rendered for the guest app as 422 with every short row: what
 * it is, how many were asked for, how many are left, and the basket lines that
 * asked — so the app can bring those lines down to what is there.
 *
 * @phpstan-type Shortage array{type: 'item'|'option', id: int, requested: int, available: int, lineKeys: list<string>}
 */
final class InsufficientStock extends RuntimeException
{
    /**
     * @param  list<Shortage>  $shortages
     */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct(OrderRefusal::InsufficientStock->message());
    }

    /**
     * One short row, in the shape the guest app reads.
     *
     * @return Shortage
     */
    public static function shortage(StockChange $take, int $available): array
    {
        return [
            'type' => $take->type === MenuItem::class ? 'item' : 'option',
            'id' => $take->id,
            'requested' => (int) $take->quantity,
            'available' => max(0, $available),
            'lineKeys' => $take->lineKeys,
        ];
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => OrderRefusal::InsufficientStock->message(),
            'reason' => OrderRefusal::InsufficientStock->value,
            'shortages' => $this->shortages,
        ], 422);
    }
}
