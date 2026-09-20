<?php

namespace App\Observers;

use App\Actions\Inventory\RecordStockMovement;
use App\Actions\Tenants\InheritParentTenant;
use App\Enums\ItemAvailability;
use App\Enums\StockMovementReason;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use LogicException;

class MenuItemObserver
{
    public function saving(MenuItem $item): void
    {
        $this->pairDietWithKind($item);
        $this->pairStockWithAvailability($item);

        app(InheritParentTenant::class)($item, MenuCategory::class, 'menu_category_id');
    }

    /**
     * An item created with a count starts its history with that count.
     */
    public function created(MenuItem $item): void
    {
        if ($item->stock_quantity > 0) {
            $user = Auth::user();

            app(RecordStockMovement::class)($item, $item->stock_quantity, StockMovementReason::Count, user: $user instanceof User ? $user : null);
        }
    }

    /**
     * An item carried to a category on another menu stops being featured: the
     * featured row belongs to one menu. MoveCategoryToMenu unfeatures a whole
     * branch itself, because moving a category changes no item's columns.
     *
     * The category is asked about first, so a save that did not fetch
     * `is_featured` — a count taken under a lock — never reads it.
     */
    public function updating(MenuItem $item): void
    {
        if (! $item->isDirty('menu_category_id') || ! $item->is_featured) {
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
     * Keep the diet marks in step with the kind, as the CHECK constraints do.
     *
     * MenuItemKind::requiresDietMark() is the single source of truth for the
     * pairing: a kind that does not require one has its marks cleared, which is
     * what lets an item change kind at all; a kind that does require one
     * refuses to save without at least one mark, and refuses two that
     * contradict each other. A saved item whose kind and marks are both
     * untouched is left alone, so renumbering a list reads no column it was
     * not given.
     */
    private function pairDietWithKind(MenuItem $item): void
    {
        if ($item->exists && ! $item->isDirty(['kind', 'diets'])) {
            return;
        }

        if (! $item->kind->requiresDietMark()) {
            $item->diets = null;

            return;
        }

        $diets = $item->diets;

        throw_if(
            $diets === null || $diets->isEmpty(),
            LogicException::class,
            "An item of kind {$item->kind->label()} needs a diet mark.",
        );

        foreach ($diets as $diet) {
            foreach ($diets as $other) {
                throw_unless(
                    $diet->goesWith($other),
                    LogicException::class,
                    "An item cannot be both {$diet->label()} and {$other->label()}.",
                );
            }
        }
    }

    /**
     * None left is never available, and stock arriving for an item that had run out puts it back.
     *
     * Only "out of stock" is ever lifted, and only by a count going up on a saved
     * item: one switched off for another reason stays off however much arrives,
     * an order only ever lowers a count, and a new item keeps whatever
     * availability it was given. The database says the first half too
     * (`menu_items_none_left_is_not_available`). A save that touched neither
     * column, or did not fetch the count, is left alone.
     */
    private function pairStockWithAvailability(MenuItem $item): void
    {
        if (! array_key_exists('stock_quantity', $item->getAttributes())) {
            return;
        }

        if ($item->exists && ! $item->isDirty(['stock_quantity', 'availability'])) {
            return;
        }

        $left = $item->stock_quantity;

        if ($left === null) {
            return;
        }

        if ($left === 0 && $item->availability === ItemAvailability::Available) {
            $item->availability = ItemAvailability::OutOfStock;

            return;
        }

        $restocked = $item->exists
            && $item->isDirty('stock_quantity')
            && $left > (int) $item->getOriginal('stock_quantity');

        if ($restocked && $item->availability === ItemAvailability::OutOfStock) {
            $item->availability = ItemAvailability::Available;
        }
    }
}
