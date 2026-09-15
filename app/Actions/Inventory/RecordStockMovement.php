<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementReason;
use App\Models\MenuAddOnOption;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;

/**
 * Write one line of a count's history.
 *
 * The only place a stock movement is written. Called once the count itself has
 * been saved, so how many are left is read off the row rather than worked out a
 * second time.
 */
final class RecordStockMovement
{
    public function __invoke(
        MenuItem|MenuAddOnOption $row,
        int $change,
        StockMovementReason $reason,
        ?Order $order = null,
        ?User $user = null,
        ?string $note = null,
    ): StockMovement {
        $movement = new StockMovement([
            'menu_item_id' => $row instanceof MenuItem ? $row->getKey() : null,
            'menu_add_on_option_id' => $row instanceof MenuAddOnOption ? $row->getKey() : null,
            'reason' => $reason,
            'quantity_change' => $change,
            'quantity_after' => (int) $row->stock_quantity,
            'order_id' => $order?->getKey(),
            'user_id' => $user?->getKey(),
            'note' => $note,
        ]);

        $movement->forceFill(['tenant_id' => $row->tenant_id])->save();

        return $movement;
    }
}
