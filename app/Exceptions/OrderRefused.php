<?php

namespace App\Exceptions;

use App\Enums\OrderRefusal;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An order refused before it touched stock: ordering is off, the menu is not being served, or a line no longer stands.
 *
 * Rendered for the guest app as 422 with the reason and, when a line is why,
 * every line as QuoteBasket priced it — the same statuses a quote carries.
 */
final class OrderRefused extends RuntimeException
{
    /**
     * @param  list<array{key: string, status: string, unitPriceMinorUnits: int, totalMinorUnits: int}>  $lines
     */
    public function __construct(public readonly OrderRefusal $reason, public readonly array $lines = [])
    {
        parent::__construct($reason->message());
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->reason->message(),
            'reason' => $this->reason->value,
            'lines' => $this->lines,
        ], 422);
    }
}
