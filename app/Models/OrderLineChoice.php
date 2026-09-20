<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatedNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An option picked for one of an order line's item — "2 × Extra cheese" — at the price it added then.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_line_id
 * @property-read OrderLine $orderLine
 * @property int|null $menu_add_on_option_id
 * @property string $name
 * @property int $quantity
 * @property int $price
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['menu_add_on_option_id', 'name', 'quantity', 'price'])]
class OrderLineChoice extends Model
{
    use HasTranslatedNames;

    /** @var list<string> */
    public array $translatable = ['name'];

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /**
     * @return BelongsTo<MenuAddOnOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(MenuAddOnOption::class, 'menu_add_on_option_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_line_id' => 'integer',
            'menu_add_on_option_id' => 'integer',
            'quantity' => 'integer',
            'price' => 'integer',
        ];
    }
}
