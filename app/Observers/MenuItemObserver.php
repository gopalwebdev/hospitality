<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Models\MenuCategory;
use App\Models\MenuItem;

class MenuItemObserver
{
    public function saving(MenuItem $item): void
    {
        app(InheritParentTenant::class)($item, MenuCategory::class, 'menu_category_id');
    }

    /**
     * A dish carried to a category on another menu stops being featured: the
     * featured row belongs to one menu. MoveCategoryToMenu unfeatures a whole
     * branch itself, because moving a category changes no dish's columns.
     */
    public function updating(MenuItem $item): void
    {
        if (! $item->is_featured || ! $item->isDirty('menu_category_id')) {
            return;
        }

        $menus = MenuCategory::query()
            ->withoutGlobalScopes()
            ->whereKey([$item->getOriginal('menu_category_id'), $item->menu_category_id])
            ->pluck('menu_id', 'id');

        if (($menus[$item->getOriginal('menu_category_id')] ?? null) !== ($menus[$item->menu_category_id] ?? null)) {
            $item->is_featured = false;
            $item->featured_position = 0;
        }
    }
}
