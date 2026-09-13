<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Models\Menu;
use App\Models\MenuCombo;

class MenuComboObserver
{
    public function saving(MenuCombo $combo): void
    {
        app(InheritParentTenant::class)($combo, Menu::class, 'menu_id');
    }
}
