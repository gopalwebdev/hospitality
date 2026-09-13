<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Models\MenuItem;
use App\Models\MenuItemAddition;

class MenuItemAdditionObserver
{
    public function saving(MenuItemAddition $addition): void
    {
        app(InheritParentTenant::class)($addition, MenuItem::class, 'menu_item_id');
    }
}
