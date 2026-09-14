<?php

namespace App\Actions\Menus;

use App\Models\Menu;
use App\Models\MenuBlock;
use App\Models\MenuCategory;
use Illuminate\Support\Facades\DB;

/**
 * Put a menu's outline in the order an admin has just dragged it into.
 *
 * The menu page lists a menu's blocks (its featured items and its combos), its
 * categories and, under each, its sub-categories, so a drag arrives as one flat
 * list of keys. This turns that back into the positions the menu stores, on
 * `menu_blocks` and `menu_categories`. What is *inside* a category or a block is
 * not on that page: it is ordered in the table the row opens, by Filament's own
 * reorder.
 *
 * **A row only moves within its own list.** The lists are the top level (blocks
 * and categories ordered against each other) and the sub-categories of one
 * category. A sub-category dropped under another category keeps the parent it
 * had and lands at the matching place among its own siblings, so no drag can
 * produce a menu that could not exist. Re-parenting is an edit on the row's own
 * form (.ai/rules/actions-menus.md).
 *
 * **A row the table did not draw keeps its place.** A block with nothing in it
 * is not drawn, so it is not in the order; only the rows that were sent are
 * shuffled, among the slots they already held.
 *
 * Keys are formatted here as well as parsed here, so the table that renders
 * them and the action that reads them cannot drift apart.
 */
class ApplyMenuArrangement
{
    /**
     * The key identifying a block's row: its type for a block every menu has, its id for any other.
     */
    public static function blockKey(MenuBlock $block): string
    {
        return $block->type->isOnEveryMenu() ? $block->type->value : 'block-'.$block->getKey();
    }

    /**
     * The key identifying a category's row, at either level.
     */
    public static function categoryKey(int $id): string
    {
        return 'category-'.$id;
    }

    /**
     * Renumber every list on this menu that the drag touched.
     *
     * @param  list<string>  $order  the rows of the table, in their new order
     */
    public function __invoke(Menu $menu, array $order): void
    {
        $rank = array_flip(array_values($order));

        // The select carries what MenuCategoryObserver reads as well as what
        // this writes — menu_id and parent_id. Model::shouldBeStrict() throws on
        // an attribute that was never fetched.
        $categories = MenuCategory::query()
            ->select(['id', 'menu_id', 'tenant_id', 'parent_id', 'position'])
            ->where('menu_id', $menu->getKey())
            ->inMenuOrder()
            ->get();

        $blocks = MenuBlock::query()
            ->select(['id', 'menu_id', 'tenant_id', 'type', 'position'])
            ->where('menu_id', $menu->getKey())
            ->get();

        DB::transaction(function () use ($menu, $rank, $categories, $blocks): void {
            $this->renumber(
                $menu->readingOrder($categories->whereNull('parent_id'), $blocks),
                $rank,
                static fn (MenuBlock|MenuCategory $row): string => $row instanceof MenuBlock
                    ? static::blockKey($row)
                    : static::categoryKey($row->getKey()),
            );

            foreach ($categories->whereNotNull('parent_id')->groupBy('parent_id') as $children) {
                $this->renumber($children->all(), $rank, static fn (MenuCategory $child): string => static::categoryKey($child->getKey()));
            }
        });
    }

    /**
     * Put one list in the order dragged, and write only the rows that moved.
     *
     * The rows that were sent swap between the slots they held; a row that was
     * not sent stays in its slot. A list the drag did not touch is left alone
     * entirely, gaps in its numbering and all.
     *
     * A block every menu has but nobody has placed is unsaved and reads at 0,
     * so it is written the first time it lands anywhere else.
     *
     * @template TRow of MenuBlock|MenuCategory
     *
     * @param  array<int, TRow>  $siblings  in their current order
     * @param  array<string, int>  $rank
     * @param  callable(TRow): string  $keyFor
     */
    private function renumber(array $siblings, array $rank, callable $keyFor): void
    {
        $slots = [];
        $sent = [];

        foreach ($siblings as $slot => $sibling) {
            $key = $keyFor($sibling);

            if (array_key_exists($key, $rank)) {
                $slots[] = $slot;
                $sent[] = [$rank[$key], $sibling];
            }
        }

        if ($sent === []) {
            return;
        }

        usort($sent, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($slots as $index => $slot) {
            $siblings[$slot] = $sent[$index][1];
        }

        foreach (array_values($siblings) as $position => $sibling) {
            if ($sibling->position === $position) {
                continue;
            }

            $sibling->position = $position;
            $sibling->save();
        }
    }
}
