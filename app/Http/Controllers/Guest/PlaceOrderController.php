<?php

namespace App\Http\Controllers\Guest;

use App\Actions\Orders\PlaceOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guest\PlaceOrderRequest;
use App\Models\Menu;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Place a guest's basket as an order, taking what it needs from stock.
 *
 * JSON for the guest app, which does not call it yet. A refusal renders itself
 * as 422: OrderRefused when ordering is off, the menu is not being served or a
 * line no longer stands, and InsufficientStock — with what every short row has
 * left — when something ran out. See PlaceOrder.
 */
class PlaceOrderController extends Controller
{
    public function __invoke(PlaceOrderRequest $request, Tenant $tenant, Menu $menu, PlaceOrder $placeOrder): JsonResponse
    {
        abort_unless($tenant->is_active, 404);

        // The tenant comes from the subdomain rather than the path, so
        // scoped bindings do not cover this and the check is made by hand.
        abort_unless($menu->tenant_id === $tenant->getKey(), 404);
        abort_unless($menu->is_active, 404);

        /** @var list<array{key: string, type: string, id: int, quantity: int, choices?: list<array{optionId: int, quantity: int}>}> $lines */
        $lines = $request->validated('lines');

        $locationLabel = $request->validated('locationLabel');
        $note = $request->validated('note');

        $order = $placeOrder(
            $tenant,
            $menu,
            $lines,
            is_string($locationLabel) ? $locationLabel : null,
            is_string($note) ? $note : null,
        );

        return response()->json([
            'orderId' => $order->getKey(),
            'totalMinorUnits' => $order->total_minor_units,
        ], 201);
    }
}
