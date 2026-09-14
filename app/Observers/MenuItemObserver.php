<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use LogicException;

class MenuItemObserver
{
    public function saving(MenuItem $item): void
    {
        $this->pairDietWithService($item);

        app(InheritParentTenant::class)($item, MenuCategory::class, 'menu_category_id');
    }

    /**
     * An item carried to a category on another menu stops being featured: the
     * featured row belongs to one menu. MoveCategoryToMenu unfeatures a whole
     * branch itself, because moving a category changes no item's columns.
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

    /**
     * Keep the diet mark in step with the service flag, as the CHECK constraint does.
     *
     * A service request has its diet cleared, which is what lets an item become
     * one at all; anything else refuses to save without a diet. A saved item
     * whose flag and diet are both untouched is left alone, so renumbering a list
     * reads no column it was not given.
     */
    private function pairDietWithService(MenuItem $item): void
    {
        if ($item->exists && ! $item->isDirty(['is_service_request', 'diet'])) {
            return;
        }

        if ($item->is_service_request) {
            $item->diet = null;

            return;
        }

        throw_if(
            $item->diet === null,
            LogicException::class,
            'An item that is not a service request needs a diet mark.',
        );
    }
}
