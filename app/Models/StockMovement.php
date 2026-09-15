<?php

namespace App\Models;

use App\Enums\StockMovementReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to an item's or an option's count, and why. Never edited.
 * Written only by App\Actions\Inventory\RecordStockMovement.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $menu_item_id
 * @property int|null $menu_add_on_option_id
 * @property StockMovementReason $reason
 * @property int $quantity_change
 * @property int $quantity_after
 * @property int|null $order_id
 * @property int|null $user_id
 * @property-read User|null $user
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 */
#[Fillable([
    'menu_item_id',
    'menu_add_on_option_id',
    'reason',
    'quantity_change',
    'quantity_after',
    'order_id',
    'user_id',
    'note',
])]
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /**
     * @return BelongsTo<MenuAddOnOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(MenuAddOnOption::class, 'menu_add_on_option_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'menu_item_id' => 'integer',
            'menu_add_on_option_id' => 'integer',
            'reason' => StockMovementReason::class,
            'quantity_change' => 'integer',
            'quantity_after' => 'integer',
            'order_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
