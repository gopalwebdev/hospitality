<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;

class MenuAddOnOptionObserver
{
    public function saving(MenuAddOnOption $option): void
    {
        app(InheritParentTenant::class)($option, MenuAddOnGroup::class, 'menu_add_on_group_id');
    }
}
