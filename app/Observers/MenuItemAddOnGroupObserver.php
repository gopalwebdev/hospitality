<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Models\MenuAddOnGroup;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;

class MenuItemAddOnGroupObserver
{
    /**
     * Take the item's tenant, and refuse a group from any other.
     */
    public function saving(MenuItemAddOnGroup $link): void
    {
        $inheritParentTenant = app(InheritParentTenant::class);

        $inheritParentTenant($link, MenuItem::class, 'menu_item_id');
        $inheritParentTenant($link, MenuAddOnGroup::class, 'menu_add_on_group_id');
    }
}
