<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Models\Menu;
use App\Models\MenuRail;

class MenuRailObserver
{
    public function saving(MenuRail $rail): void
    {
        app(InheritParentTenant::class)($rail, Menu::class, 'menu_id');
    }
}
