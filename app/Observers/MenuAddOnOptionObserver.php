<?php

namespace App\Observers;

use App\Actions\Inventory\RecordStockMovement;
use App\Actions\Tenants\InheritParentTenant;
use App\Enums\StockMovementReason;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class MenuAddOnOptionObserver
{
    public function saving(MenuAddOnOption $option): void
    {
        app(InheritParentTenant::class)($option, MenuAddOnGroup::class, 'menu_add_on_group_id');
    }

    /**
     * An option created with a count starts its history with that count.
     */
    public function created(MenuAddOnOption $option): void
    {
        if ($option->stock_quantity > 0) {
            $user = Auth::user();

            app(RecordStockMovement::class)($option, $option->stock_quantity, StockMovementReason::Count, user: $user instanceof User ? $user : null);
        }
    }
}
