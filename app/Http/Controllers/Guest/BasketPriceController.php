<?php

namespace App\Http\Controllers\Guest;

use App\Actions\Baskets\PriceBasket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guest\PriceBasketRequest;
use App\Models\Menu;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * What a guest's basket comes to, priced against the menu it was built on.
 *
 * The basket is kept on the phone, so the app asks again whenever it changes:
 * the answer is PHP's and the app only formats it. See PriceBasket.
 */
class BasketPriceController extends Controller
{
    public function __invoke(PriceBasketRequest $request, Tenant $tenant, Menu $menu, PriceBasket $priceBasket): JsonResponse
    {
        abort_unless($tenant->is_active, 404);

        // The tenant comes from the subdomain rather than the path, so
        // scoped bindings do not cover this and the check is made by hand.
        abort_unless($menu->tenant_id === $tenant->getKey(), 404);
        abort_unless($menu->is_active, 404);

        /** @var list<array{key: string, type: string, id: int, quantity: int, choices?: list<array{optionId: int, quantity: int}>}> $lines */
        $lines = $request->validated('lines');

        return response()->json($priceBasket($tenant, $menu, $lines));
    }
}
