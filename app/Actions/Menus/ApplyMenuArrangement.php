<?php

namespace App\Actions\Menus;

use App\Enums\MenuBlock;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Put a menu in the order an admin has just dragged it into.
 *
 * The arrangement screen is one flat table holding four kinds of row — the
 * featured rail, the combos rail, the categories at both levels, and the items
 * in each — so a drag arrives as one flat list of keys covering the whole menu.
 * This turns that back into the four kinds of `position` the menu actually
 * stores.
 *
 * **A row only moves within its own list.** The lists are: the top level (the
 * two rails and the categories, all ordered against each other), the items of
 * one category, and the sub-categories of one category. A row dropped into
 * another branch keeps the parent it had and simply lands at the matching place
 * among its own siblings — which is what makes this total: no drag can produce
 * a menu that could not exist.
 *
 * That is deliberate rather than a limitation. Re-filing an item or a
 * sub-category is an edit on its own form, where the parent is a select and the
 * name is revalidated against where it is going (.ai/rules/actions-menus.md).
 * A drag that re-parented would be a second mechanism having to repeat that
 * uniqueness rule, and getting it wrong would store a duplicate name that
 * nothing else refuses.
 *
 * Keys are formatted here as well as parsed here, so the table that renders
 * them and the action that reads them cannot drift apart.
 */
class ApplyMenuArrangement
{
    /**
     * The key identifying a category's row, at either level.
     */
    public static function categoryKey(int $id): string
    {
        return 'category-'.$id;
    }

    /**
     * The key identifying an item's row.
     */
    public static function itemKey(int $id): string
    {
        return 'item-'.$id;
    }

    /**
     * Renumber every list on this menu to match the order dragged.
     *
     * @param  list<string>  $order  every row of the table, in its new order
     */
    public function __invoke(Menu $menu, array $order): void
    {
        $rank = array_flip(array_values($order));

        // Each select carries what its model's own saving hooks read as well as
        // what this writes: MenuCategoryObserver looks at menu_id and
        // parent_id, MenuItemObserver at is_featured — and at is_service and
        // diet only when one of them changes, which a renumber never does.
        // Model::shouldBeStrict() throws on an attribute that was never fetched.
        $categories = MenuCategory::query()
            ->select(['id', 'menu_id', 'tenant_id', 'parent_id', 'position'])
            ->where('menu_id', $menu->getKey())
            ->orderBy('position')
            ->get();

        $items = MenuItem::query()
            ->select(['id', 'menu_category_id', 'is_featured', 'position'])
            ->whereIn('menu_category_id', $categories->pluck('id'))
            ->orderBy('position')
            ->get();

        DB::transaction(function () use ($menu, $categories, $items, $rank): void {
            $this->arrangeTopLevel($menu, $categories, $rank);

            foreach ($categories->groupBy('parent_id') as $parentId => $children) {
                // The top level is arranged above, rails and all.
                if ($parentId === null || $parentId === '') {
                    continue;
                }

                $this->writePositions($children, $rank, static::categoryKey(...));
            }

            foreach ($items->groupBy('menu_category_id') as $siblings) {
                $this->writePositions($siblings, $rank, static::itemKey(...));
            }
        });
    }

    /**
     * Order the two rails and the categories against each other.
     *
     * All three share one number space, so dragging the combos rail below
     * Starters is the same kind of move as dragging Desserts below it.
     *
     * @param  Collection<int, MenuCategory>  $categories
     * @param  array<string, int>  $rank
     */
    private function arrangeTopLevel(Menu $menu, Collection $categories, array $rank): void
    {
        /** @var list<array{0: int, 1: MenuBlock|MenuCategory}> $blocks */
        $blocks = [];

        foreach (MenuBlock::cases() as $rail) {
            $blocks[] = [$rank[$rail->value] ?? PHP_INT_MAX, $rail];
        }

        foreach ($categories->whereNull('parent_id') as $category) {
            $blocks[] = [$rank[static::categoryKey($category->getKey())] ?? PHP_INT_MAX, $category];
        }

        // Stable, so a row the table did not send keeps the place its current
        // position gave it — the collection is already in position order.
        usort($blocks, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $railPositions = [];

        foreach ($blocks as $position => [, $block]) {
            if ($block instanceof MenuBlock) {
                $railPositions[$block->positionColumn()] = $position;

                continue;
            }

            if ($block->position !== $position) {
                $block->update(['position' => $position]);
            }
        }

        if ($railPositions !== [] && $this->railsMoved($menu, $railPositions)) {
            $menu->update($railPositions);
        }
    }

    /**
     * Whether either rail is not already where the drag put it.
     *
     * @param  array<string, int>  $railPositions
     */
    private function railsMoved(Menu $menu, array $railPositions): bool
    {
        return array_any($railPositions, fn (int $position, $column): bool => (int) $menu->getAttribute($column) !== $position);
    }

    /**
     * Renumber one list of siblings, leaving the rows that have not moved.
     *
     * @template TRow of MenuCategory|MenuItem
     *
     * @param  Collection<int, TRow>  $siblings  in their current order
     * @param  array<string, int>  $rank
     * @param  callable(int): string  $keyFor
     */
    private function writePositions(Collection $siblings, array $rank, callable $keyFor): void
    {
        $ordered = $siblings
            ->sortBy(static fn (MenuCategory|MenuItem $sibling): int => $rank[$keyFor($sibling->getKey())] ?? PHP_INT_MAX)
            ->values();

        foreach ($ordered as $position => $sibling) {
            if ($sibling->position !== $position) {
                $sibling->update(['position' => $position]);
            }
        }
    }
}
