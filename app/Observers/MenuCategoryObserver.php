<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Models\Menu;
use App\Models\MenuCategory;
use LogicException;

class MenuCategoryObserver
{
    /**
     * Take the menu's tenant, and keep a sub-category one level deep and on
     * the same menu as its parent.
     */
    public function saving(MenuCategory $category): void
    {
        app(InheritParentTenant::class)($category, Menu::class, 'menu_id');

        // Rearranging a menu saves every row; only a parent being set needs asking about.
        if (! $category->isDirty(['parent_id', 'menu_id']) || blank($category->parent_id)) {
            return;
        }

        throw_if(
            (int) $category->parent_id === (int) $category->getKey(),
            LogicException::class,
            'A category cannot be its own parent.',
        );

        $parent = MenuCategory::query()
            ->withoutGlobalScopes()
            ->whereKey($category->parent_id)
            ->first(['id', 'menu_id', 'parent_id']);

        throw_if(
            $parent?->parent_id !== null,
            LogicException::class,
            'A menu is two levels deep: a sub-category cannot hold another.',
        );

        throw_if(
            (int) $parent?->menu_id !== (int) $category->menu_id,
            LogicException::class,
            'A sub-category sits on the same menu as its parent.',
        );
    }
}
