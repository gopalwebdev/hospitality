<?php

namespace App\Http\Requests\Guest;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The shape of a basket a guest's phone sends to be placed as an order.
 *
 * A basket as it is priced, which has to hold something, and where to bring
 * it. Only the shape: whether it can still be had, and whether enough is left,
 * is PlaceOrder's to decide against what is saved.
 */
class PlaceOrderRequest extends QuoteBasketRequest
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
            'locationLabel' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
