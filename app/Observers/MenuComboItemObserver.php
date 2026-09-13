<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Models\MenuCombo;
use App\Models\MenuComboItem;
use App\Models\MenuItem;

class MenuComboItemObserver
{
    /**
     * Take the combo's tenant, and refuse a dish from any other.
     */
    public function saving(MenuComboItem $comboItem): void
    {
        $inheritParentTenant = app(InheritParentTenant::class);

        $inheritParentTenant($comboItem, MenuCombo::class, 'menu_combo_id');
        $inheritParentTenant($comboItem, MenuItem::class, 'menu_item_id');
    }
}
