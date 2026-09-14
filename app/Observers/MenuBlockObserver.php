<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Models\Menu;
use App\Models\MenuBlock;

class MenuBlockObserver
{
    public function saving(MenuBlock $block): void
    {
        app(InheritParentTenant::class)($block, Menu::class, 'menu_id');
    }
}
