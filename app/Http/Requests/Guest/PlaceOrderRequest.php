<?php

namespace App\Http\Requests\Guest;

use App\Enums\OrderSettlement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The shape of a basket a guest's phone sends to be placed as an order.
 *
 * A basket as it is priced, which has to hold something, and where to bring
 * it. Only the shape: whether it can still be had, whether enough is left, and
 * whether a picked location is really this tenant's own and active, is
 * PlaceOrder's and its controller's to decide against what is saved.
 */
class PlaceOrderRequest extends PriceBasketRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    #[\Override]
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            // One of this tenant's own locations, checked by hand in the
            // controller: the tenant comes from the subdomain, so a scoped
            // binding cannot do it here.
            'locationId' => ['nullable', 'integer', 'min:1'],
            // Defaults to AddToBill when absent; the guest's intent, not the
            // truth about the money.
            'settlement' => ['nullable', Rule::enum(OrderSettlement::class)],
            // Free text, for a tenant with no locations set up, or a guest who
            // typed instead of picking. Ignored when locationId also arrives.
            'locationLabel' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
