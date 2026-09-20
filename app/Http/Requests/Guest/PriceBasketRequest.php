<?php

namespace App\Http\Requests\Guest;

use App\Actions\Menus\PriceBasket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shape of a basket a guest's phone sends to be priced.
 *
 * Only the shape. Whether an item is still on the menu, and whether its choices
 * still meet its groups, is PriceBasket's to decide against what is saved.
 */
class PriceBasketRequest extends FormRequest
{
    /**
     * Anyone reading a menu may price a basket from it; there is no account to check.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:50'],
            'lines.*.key' => ['required', 'string', 'max:200'],
            'lines.*.type' => ['required', 'string', Rule::in([PriceBasket::ITEM, PriceBasket::COMBO])],
            'lines.*.id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.choices' => ['sometimes', 'array', 'max:50'],
            'lines.*.choices.*.optionId' => ['required', 'integer', 'min:1'],
            'lines.*.choices.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
