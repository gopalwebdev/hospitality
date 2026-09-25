<?php

namespace App\Actions\Menus;

use App\Enums\ItemAvailability;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Everything on one menu that can be ordered right now, laid out for the panel's Take order page.
 *
 * The same menu Guest\MenuController sends a phone, shaped for a different
 * screen. Which things can be had is not decided again here: it is the model's
 * own answer — MenuItem::orderable(), MenuAddOnOption::available(), and
 * MenuAddOnGroup::canBeMetBy()/picksOffered() for an item whose required group
 * its remaining options can no longer meet — so a sold-out item is absent from
 * the counter for exactly the reason it is absent from the phone.
 *
 * What differs is the shape, and deliberately:
 * - **Flat sections.** A guest scrolls a nested card; staff scan a grid, so a
 *   sub-category is a section of its own, named under its parent, rather than a
 *   heading inside one.
 * - **No rails.** Featured items and the tenant's chosen reading order are a
 *   guest's first impression; at the counter an item is found by its section or
 *   by typing its name. A featured item is in its own section here, once.
 * - **Models, not a payload.** Nothing crosses a wire, so the rows come back as
 *   they are and the page reads `$item->price`. The add-on groups come back as
 *   models for the same reason and one reason more: the customise modal asks
 *   `MenuAddOnGroup::quantityAllowedFor()` how many of an option one item may
 *   take, rather than restating that rule in Blade.
 *
 * Groups are returned once for the whole menu and each item names the ones it
 * offers with its own cap on each, exactly as the guest payload does: a spice
 * level on twenty items is one group in memory, not twenty copies of it.
 *
 * @phpstan-type GroupLink array{id: int, maxPicks: int|null}
 * @phpstan-type OrderableTile array{type: string, key: string, model: MenuItem|MenuCombo, groupLinks: list<GroupLink>}
 * @phpstan-type OrderableSection array{key: string, name: string, tiles: list<OrderableTile>}
 */
final readonly class ReadOrderableMenu
{
    public const string ITEM = 'item';

    public const string COMBO = 'combo';

    /**
     * @return array{sections: list<OrderableSection>, groups: EloquentCollection<int, MenuAddOnGroup>}
     */
    public function __invoke(Menu $menu): array
    {
        $categories = $this->categories($menu);
        $items = $this->itemsIn($categories);
        $links = $this->groupLinksByItem($items);
        $groups = $this->groups($links);

        $sections = [];

        foreach ($this->combos($menu) as $combo) {
            $sections[self::COMBO] ??= ['key' => self::COMBO, 'name' => __('panel.combos.plural'), 'tiles' => []];
            $sections[self::COMBO]['tiles'][] = [
                'type' => self::COMBO,
                'key' => self::COMBO.':'.$combo->getKey(),
                'model' => $combo,
                'groupLinks' => [],
            ];
        }

        foreach ($categories as $category) {
            $this->addSection($sections, (string) $category->getKey(), $category->name, $category->menuItems, $links, $groups);

            foreach ($category->children as $child) {
                // Named under its parent rather than nested: on a grid a bare
                // "Vegetarian" says nothing about which section it divides.
                $this->addSection($sections, (string) $child->getKey(), $category->name.' · '.$child->name, $child->menuItems, $links, $groups);
            }
        }

        return [
            'sections' => array_values($sections),
            'groups' => $groups,
        ];
    }

    /**
     * Add one section, unless nothing in it can be ordered.
     *
     * @param  array<string, OrderableSection>  $sections
     * @param  EloquentCollection<int, MenuItem>  $items
     * @param  array<int, non-empty-list<GroupLink>>  $links
     * @param  EloquentCollection<int, MenuAddOnGroup>  $groups
     */
    private function addSection(array &$sections, string $key, string $name, EloquentCollection $items, array $links, EloquentCollection $groups): void
    {
        $tiles = [];

        foreach ($items as $item) {
            $itemLinks = $this->offeredLinks($item, $links, $groups);

            if ($itemLinks === null) {
                continue;
            }

            $tiles[] = [
                'type' => self::ITEM,
                'key' => self::ITEM.':'.$item->getKey(),
                'model' => $item,
                'groupLinks' => $itemLinks,
            ];
        }

        if ($tiles === []) {
            return;
        }

        $sections[$key] = ['key' => $key, 'name' => $name, 'tiles' => $tiles];
    }

    /**
     * The groups this item actually offers, or null when one it requires can no longer be met.
     *
     * A group whose last available option has run out is dropped; a *required*
     * group in that state takes the whole item off the counter, because
     * PriceBasket would refuse every line of it anyway.
     *
     * @param  array<int, non-empty-list<GroupLink>>  $links
     * @param  EloquentCollection<int, MenuAddOnGroup>  $groups
     * @return list<GroupLink>|null
     */
    private function offeredLinks(MenuItem $item, array $links, EloquentCollection $groups): ?array
    {
        $offered = [];

        foreach ($links[$item->getKey()] ?? [] as $link) {
            $group = $groups->get($link['id']);

            if (! $group instanceof MenuAddOnGroup) {
                continue;
            }

            if (! $group->canBeMetBy($group->picksOffered($link['maxPicks']))) {
                return null;
            }

            if ($group->options->isNotEmpty()) {
                $offered[] = $link;
            }
        }

        return $offered;
    }

    /**
     * This menu's sections and their subdivisions, each with the items filed under it.
     *
     * @return EloquentCollection<int, MenuCategory>
     */
    private function categories(Menu $menu): EloquentCollection
    {
        $orderableItems = fn ($items) => $items
            ->select($this->itemColumns())
            ->orderable()
            ->inMenuOrder();

        return MenuCategory::query()
            ->select(['id', 'parent_id', 'name'])
            ->where('menu_id', $menu->getKey())
            ->topLevel()
            ->active()
            ->with([
                'menuItems' => $orderableItems,
                'children' => fn ($children) => $children
                    ->select(['id', 'parent_id', 'name'])
                    ->active()
                    ->with(['menuItems' => $orderableItems])
                    ->inMenuOrder(),
            ])
            ->inMenuOrder()
            ->get();
    }

    /**
     * The combos this menu leads with, minus any drawing on an item with none left.
     *
     * @return EloquentCollection<int, MenuCombo>
     */
    private function combos(Menu $menu): EloquentCollection
    {
        return MenuCombo::query()
            ->select(['id', 'name', 'description', 'price', 'original_price', 'max_per_order'])
            ->where('menu_id', $menu->getKey())
            ->whereIn('availability', ItemAvailability::orderableValues())
            // A combo has no count of its own and draws on its items', so one
            // holding a counted item with none left cannot be had either.
            ->whereDoesntHave('comboItems.menuItem', fn ($item) => $item->where('stock_quantity', 0))
            ->inMenuOrder()
            ->get();
    }

    /**
     * Every item on the page, its own section's and its subdivisions'.
     *
     * @param  EloquentCollection<int, MenuCategory>  $categories
     * @return list<MenuItem>
     */
    private function itemsIn(EloquentCollection $categories): array
    {
        $items = [];

        foreach ($categories as $category) {
            $items = [...$items, ...$category->menuItems->all()];

            foreach ($category->children as $child) {
                $items = [...$items, ...$child->menuItems->all()];
            }
        }

        return $items;
    }

    /**
     * The groups each item offers, in its own order, with its own cap on each. One query for the page.
     *
     * @param  list<MenuItem>  $items
     * @return array<int, non-empty-list<GroupLink>> keyed by item id
     */
    private function groupLinksByItem(array $items): array
    {
        $itemIds = array_values(array_unique(array_map(
            static fn (MenuItem $item): int => $item->getKey(),
            $items,
        )));

        if ($itemIds === []) {
            return [];
        }

        $links = [];

        foreach (MenuItemAddOnGroup::query()
            ->select(['id', 'menu_item_id', 'menu_add_on_group_id', 'position', 'max_picks'])
            ->whereIn('menu_item_id', $itemIds)
            ->inMenuOrder()
            ->get() as $link) {
            $links[$link->menu_item_id][] = ['id' => $link->menu_add_on_group_id, 'maxPicks' => $link->max_picks];
        }

        return $links;
    }

    /**
     * Those groups, each carrying only the options that can be had right now.
     *
     * @param  array<int, non-empty-list<GroupLink>>  $links
     * @return EloquentCollection<int, MenuAddOnGroup> keyed by id
     */
    private function groups(array $links): EloquentCollection
    {
        $ids = [];

        foreach ($links as $itemLinks) {
            foreach ($itemLinks as $link) {
                $ids[$link['id']] = true;
            }
        }

        if ($ids === []) {
            return new EloquentCollection;
        }

        return MenuAddOnGroup::query()
            ->select(['id', 'name', 'is_required', 'max_picks'])
            ->whereKey(array_keys($ids))
            ->with(['options' => fn ($options) => $options
                ->select(['id', 'menu_add_on_group_id', 'name', 'price', 'max_per_item', 'is_default'])
                ->available()
                ->inMenuOrder()])
            ->get()
            ->keyBy(fn (MenuAddOnGroup $group): int => $group->getKey());
    }

    /**
     * What a tile is read from. menu_category_id is here because Eloquent
     * needs it to attach an item to the category that loaded it.
     *
     * @return list<string>
     */
    private function itemColumns(): array
    {
        return [
            'id',
            'menu_category_id',
            'name',
            'description',
            'price',
            'original_price',
            'kind',
            'diets',
            'max_per_order',
        ];
    }
}
