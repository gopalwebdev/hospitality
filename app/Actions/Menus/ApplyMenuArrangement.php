<?php

namespace App\Actions\Menus;

use App\Models\Menu;
use App\Models\MenuBlock;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Put a menu in the order an admin has just dragged it into.
 *
 * The menu page is one flat table of everything on the menu — its blocks, the
 * featured items and combos listed under two of them, the categories at both
 * levels and the items in each — so a drag arrives as one flat list of keys.
 * This turns that back into the positions the menu stores: on `menu_blocks`,
 * `menu_categories`, `menu_items` (`position` and `featured_position`) and
 * `menu_combos`.
 *
 * **A row only moves within its own list.** The lists are the top level (blocks
 * and categories ordered against each other), the featured items, the combos,
 * the sub-categories of one category, and the items of one category. A row
 * dropped into another branch keeps the parent it had and lands at the matching
 * place among its own siblings, so no drag can produce a menu that could not
 * exist. Re-filing is an edit on the row's own form (.ai/rules/actions-menus.md).
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
     * The key identifying an item's row under its category.
     */
    public static function itemKey(int $id): string
    {
        return 'item-'.$id;
    }

    /**
     * The key identifying an item's row in the featured list, which is not its row under its category.
     */
    public static function featuredItemKey(int $id): string
    {
        return 'featured-'.$id;
    }

    /**
     * The key identifying a combo's row.
     */
    public static function comboKey(int $id): string
    {
        return 'combo-'.$id;
    }

    /**
     * Renumber every list on this menu that the drag touched.
     *
     * @param  list<string>  $order  the rows of the table, in their new order
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
            ->inMenuOrder()
            ->get();

        $items = MenuItem::query()
            ->select(['id', 'menu_category_id', 'is_featured', 'featured_position', 'position'])
            ->whereIn('menu_category_id', $categories->modelKeys())
            ->inMenuOrder()
            ->get();

        // Its own query rather than a filter over the items above: the featured
        // list has an order of its own, which is not the order of any category.
        $featured = MenuItem::query()
            ->select(['id', 'menu_category_id', 'is_featured', 'featured_position', 'position'])
            ->whereIn('menu_category_id', $categories->modelKeys())
            ->where('is_featured', true)
            ->inFeaturedOrder()
            ->get();

        $combos = MenuCombo::query()
            ->select(['id', 'menu_id', 'tenant_id', 'position'])
            ->where('menu_id', $menu->getKey())
            ->inMenuOrder()
            ->get();

        $blocks = MenuBlock::query()
            ->select(['id', 'menu_id', 'tenant_id', 'type', 'position'])
            ->where('menu_id', $menu->getKey())
            ->get();

        DB::transaction(function () use ($menu, $rank, $categories, $items, $featured, $combos, $blocks): void {
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

            foreach ($items->groupBy('menu_category_id') as $siblings) {
                $this->renumber($siblings->all(), $rank, static fn (MenuItem $item): string => static::itemKey($item->getKey()));
            }

            $this->renumber($featured->all(), $rank, static fn (MenuItem $item): string => static::featuredItemKey($item->getKey()), 'featured_position');

            $this->renumber($combos->all(), $rank, static fn (MenuCombo $combo): string => static::comboKey($combo->getKey()));
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
     * @template TRow of Model
     *
     * @param  array<int, TRow>  $siblings  in their current order
     * @param  array<string, int>  $rank
     * @param  callable(TRow): string  $keyFor
     */
    private function renumber(array $siblings, array $rank, callable $keyFor, string $column = 'position'): void
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
            if ((int) $sibling->getAttribute($column) === $position) {
                continue;
            }

            $sibling->setAttribute($column, $position);
            $sibling->save();
        }
    }
}
