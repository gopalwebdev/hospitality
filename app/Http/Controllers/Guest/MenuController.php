<?php

namespace App\Http\Controllers\Guest;

use App\Enums\ItemAvailability;
use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\MenuBlock;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuComboItem;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One of a tenant's menus, read at the table or in the room.
 *
 * The whole menu comes down together — the sections, their subdivisions, the
 * items in each, the add-on groups those items are customised with, the combos
 * the menu leads with and the charges a bill from it carries — because that is
 * one screen a guest scrolls, and fetching it in layers would be a round trip per
 * layer for one page. Both levels of section are rows of menu_categories, so the
 * subdivisions are simply the `children` of a top-level one. Each query names
 * the columns it needs, so a long menu does not carry timestamps and foreign
 * keys nobody renders.
 *
 * An add-on group is sent once for the whole menu and each item names the groups
 * it offers, in its own order: a spice level on twenty items is one group on the
 * wire, not twenty copies of it.
 *
 * Only what is actually orderable is sent: a hidden category, a hidden
 * sub-category, a sold-out item and an option that has run out are all absent
 * rather than greyed out, because a guest reading a menu on a phone should not
 * be scrolling past things they cannot have. So is an item whose required group
 * its available options can no longer meet. The *reason* an item is off never
 * reaches the guest either — App\Enums\ItemAvailability is for the tenant, and
 * "temporarily unavailable" beside an item is a worse read than the item simply
 * not being listed.
 *
 * Prices go out as integers. Turning 24950 into ₹249.50 happens in the browser
 * — see resources/js/lib/money.ts — so the server never builds a string per row
 * and the result follows the guest's own language. A zero goes out as a zero,
 * and the app names it complimentary.
 */
class MenuController extends Controller
{
    public function __invoke(Tenant $tenant, Menu $menu): Response
    {
        abort_unless($tenant->is_active, 404);

        // The tenant comes from the subdomain rather than the path, so
        // scoped bindings do not cover this and the check is made by hand.
        abort_unless($menu->tenant_id === $tenant->getKey(), 404);
        abort_unless($menu->is_active, 404);

        $orderable = ItemAvailability::orderableValues();

        $items = fn ($items) => $items
            ->select($this->itemColumns())
            ->whereIn('availability', $orderable)
            ->inMenuOrder();

        $sections = MenuCategory::query()
            // position is read as well as ordered by: it is what places a
            // section against the two rails in Menu::readingOrder().
            ->select(['id', 'parent_id', 'name', 'position'])
            ->where('menu_id', $menu->getKey())
            ->topLevel()
            ->where('is_active', true)
            ->with([
                // The items filed straight under the section, which are read
                // above its subdivisions: the general before the specific.
                'menuItems' => $items,

                'children' => fn ($children) => $children
                    ->select(['id', 'parent_id', 'name'])
                    ->where('is_active', true)
                    ->with(['menuItems' => $items])
                    ->inMenuOrder(),
            ])
            ->inMenuOrder()
            ->get();

        // The items this menu leads with, above its sections. A separate query
        // rather than a flag read off the sections above: featuring has its own
        // order, and the same item appears again under its section — a guest
        // scrolling down should find it where they expect it.
        $featured = MenuItem::query()
            ->select($this->itemColumns())
            ->where('tenant_id', $tenant->getKey())
            ->featuredOnMenu($menu->getKey())
            ->orderable()
            ->inFeaturedOrder()
            ->get();

        $groupIdsByItem = $this->addOnGroupIdsByItem($sections, $featured);
        $groups = $this->addOnGroups($groupIdsByItem);

        $canBeOrdered = fn (MenuItem $item): bool => $this->requiredGroupsCanBeMet($item, $groupIdsByItem, $groups);

        foreach ($sections as $category) {
            $category->setRelation('menuItems', $category->menuItems->filter($canBeOrdered)->values());

            foreach ($category->children as $child) {
                $child->setRelation('menuItems', $child->menuItems->filter($canBeOrdered)->values());
            }
        }

        $sections = $sections
            ->filter(fn (MenuCategory $category): bool => $this->hasAnythingToRead($category))
            ->values();

        $featured = $featured->filter($canBeOrdered)->values();

        // The groups an item offers, in its own order: those with at least one
        // option a guest can have.
        $offeredGroupIds = fn (MenuItem $item): array => array_values(array_filter(
            $groupIdsByItem[$item->getKey()] ?? [],
            fn (int $groupId): bool => $groups->get($groupId)?->options->isNotEmpty() ?? false,
        ));

        $present = fn (MenuItem $item): array => $this->presentItem($item, $offeredGroupIds($item));

        $combos = MenuCombo::query()
            ->select(['id', 'name', 'description', 'price_minor_units', 'compare_at_price_minor_units'])
            ->where('menu_id', $menu->getKey())
            ->whereIn('availability', $orderable)
            ->with(['comboItems' => fn ($comboItems) => $comboItems
                ->select(['id', 'menu_combo_id', 'menu_item_id', 'quantity'])
                ->with(['menuItem' => fn ($item) => $item->select(['id', 'name', 'is_service_request', 'diet'])])
                ->inMenuOrder()])
            ->inMenuOrder()
            ->get();

        return Inertia::render('menu', [
            'menu' => [
                'id' => $menu->getKey(),
                'name' => $menu->name,
                'description' => $menu->description,
                // Both halves or neither, so the app has one thing to check,
                // and both as HH:MM whichever driver stored them.
                'servedFrom' => $menu->servedFrom(),
                'servedUntil' => $menu->servedUntil(),
                'isBeingServed' => $menu->isBeingServedAt(),
            ],
            'featured' => $featured->map($present)->values()->all(),
            'combos' => $combos->map(fn (MenuCombo $combo): array => [
                'id' => $combo->getKey(),
                'name' => $combo->name,
                'description' => $combo->description,
                'priceMinorUnits' => $combo->price_minor_units,
                'compareAtPriceMinorUnits' => $combo->hasComparePrice() ? $combo->compare_at_price_minor_units : null,
                'contents' => $combo->comboItems->map(fn (MenuComboItem $comboItem): array => [
                    'id' => $comboItem->getKey(),
                    'name' => $comboItem->menuItem->name,
                    'isServiceRequest' => $comboItem->menuItem->is_service_request,
                    'diet' => $comboItem->menuItem->diet?->value,
                    'quantity' => $comboItem->quantity,
                ])->values()->all(),
            ])->values()->all(),
            // Where the featured and combos rails sit among the sections is the
            // tenant's decision, dragged on the menu page, so the order is
            // worked out here rather than assumed by the app. Sections with
            // nothing to read have already been filtered out, so nothing in
            // this list points at a section that was not sent.
            'order' => array_map(
                fn (MenuBlock|MenuCategory $entry): string|int => $entry instanceof MenuBlock
                    ? $entry->type->value
                    : $entry->getKey(),
                $menu->readingOrder($sections, $this->blocks($menu)),
            ),
            'sections' => $sections->map(fn (MenuCategory $category): array => [
                'id' => $category->getKey(),
                'name' => $category->name,
                'items' => $category->menuItems->map($present)->values()->all(),
                'subSections' => $category->children
                    ->filter(fn (MenuCategory $child): bool => $child->menuItems->isNotEmpty())
                    ->map(fn (MenuCategory $child): array => [
                        'id' => $child->getKey(),
                        'name' => $child->name,
                        'items' => $child->menuItems->map($present)->values()->all(),
                    ])->values()->all(),
            ])->values()->all(),
            'addOnGroups' => $this->presentAddOnGroups($groups, $this->shownItems($sections, $featured)->flatMap($offeredGroupIds)),
            'tax' => $this->tax($tenant),
            'charges' => $this->charges($tenant, $menu),
            'acceptingOrders' => $tenant->isAcceptingOrders(),
            'quoteUrl' => route('guest.menus.basket-quotes.store', ['tenant' => $tenant->slug, 'menu' => $menu->getKey()]),
            'homeUrl' => route('guest.home', ['tenant' => $tenant->slug]),
        ]);
    }

    /**
     * The add-on groups each item on the page offers, in that item's own order.
     *
     * One query for every item, featured ones included.
     *
     * @param  EloquentCollection<int, MenuCategory>  $sections
     * @param  EloquentCollection<int, MenuItem>  $featured
     * @return array<int, non-empty-list<int>> group ids, keyed by item id
     */
    private function addOnGroupIdsByItem(EloquentCollection $sections, EloquentCollection $featured): array
    {
        $itemIds = $this->shownItems($sections, $featured)
            ->map(fn (MenuItem $item): int => $item->getKey())
            ->unique()
            ->values()
            ->all();

        if ($itemIds === []) {
            return [];
        }

        $links = MenuItemAddOnGroup::query()
            ->select(['id', 'menu_item_id', 'menu_add_on_group_id', 'position'])
            ->whereIn('menu_item_id', $itemIds)
            ->inMenuOrder()
            ->get();

        $groupIds = [];

        foreach ($links as $link) {
            $groupIds[$link->menu_item_id][] = $link->menu_add_on_group_id;
        }

        return $groupIds;
    }

    /**
     * Every group those items offer, with only the options a guest can have right now.
     *
     * @param  array<int, non-empty-list<int>>  $groupIdsByItem
     * @return EloquentCollection<int, MenuAddOnGroup> keyed by id
     */
    private function addOnGroups(array $groupIdsByItem): EloquentCollection
    {
        $groupIds = array_values(array_unique(array_merge(...array_values($groupIdsByItem))));

        if ($groupIds === []) {
            return new EloquentCollection;
        }

        return MenuAddOnGroup::query()
            ->select(['id', 'name', 'min_selections', 'max_selections', 'allows_quantities'])
            ->whereKey($groupIds)
            ->with(['options' => fn ($options) => $options
                ->select(['id', 'menu_add_on_group_id', 'name', 'price_minor_units', 'max_quantity', 'is_default'])
                ->available()
                ->inMenuOrder()])
            ->get()
            ->keyBy(fn (MenuAddOnGroup $group): int => $group->getKey());
    }

    /**
     * Whether a guest could still complete every group an item makes them choose from.
     *
     * @param  array<int, non-empty-list<int>>  $groupIdsByItem
     * @param  EloquentCollection<int, MenuAddOnGroup>  $groups
     */
    private function requiredGroupsCanBeMet(MenuItem $item, array $groupIdsByItem, EloquentCollection $groups): bool
    {
        foreach ($groupIdsByItem[$item->getKey()] ?? [] as $groupId) {
            $group = $groups->get($groupId);

            if ($group instanceof MenuAddOnGroup && ! $group->canBeMetBy($group->picksOffered())) {
                return false;
            }
        }

        return true;
    }

    /**
     * The groups the page's items actually offer, each with its options.
     *
     * @param  EloquentCollection<int, MenuAddOnGroup>  $groups
     * @param  Collection<int, int>  $offeredIds
     * @return list<array<string, mixed>>
     */
    private function presentAddOnGroups(EloquentCollection $groups, Collection $offeredIds): array
    {
        $offered = $offeredIds->unique()->flip();

        return array_values($groups
            ->filter(fn (MenuAddOnGroup $group): bool => $offered->has($group->getKey()))
            ->map(fn (MenuAddOnGroup $group): array => [
                'id' => $group->getKey(),
                'name' => $group->name,
                'minSelections' => $group->min_selections,
                // Null is no limit, and the app reads it that way.
                'maxSelections' => $group->max_selections,
                'options' => $group->options->map(fn (MenuAddOnOption $option): array => [
                    'id' => $option->getKey(),
                    'name' => $option->name,
                    // Zero is a real price; the app shows no price beside it
                    // rather than "+ ₹0.00", which reads as a mistake.
                    'priceMinorUnits' => $option->price_minor_units,
                    // One whenever the group does not allow the same option
                    // twice, whatever the option's own cap says.
                    'maxQuantity' => $group->quantityAllowedFor($option),
                    'isDefault' => $option->is_default,
                ])->values()->all(),
            ])
            ->all());
    }

    /**
     * Every item the page shows: the featured rail, and every section's own and its subdivisions'.
     *
     * @param  EloquentCollection<int, MenuCategory>  $sections
     * @param  EloquentCollection<int, MenuItem>  $featured
     * @return Collection<int, MenuItem>
     */
    private function shownItems(EloquentCollection $sections, EloquentCollection $featured): Collection
    {
        $items = collect($featured->all());

        foreach ($sections as $category) {
            $items->push(...$category->menuItems->all());

            foreach ($category->children as $child) {
                $items->push(...$child->menuItems->all());
            }
        }

        return $items;
    }

    /**
     * Where this menu's blocks have been placed. Featured and combos have no row until they are.
     *
     * @return EloquentCollection<int, MenuBlock>
     */
    private function blocks(Menu $menu): EloquentCollection
    {
        return MenuBlock::query()
            ->select(['id', 'menu_id', 'type', 'position'])
            ->where('menu_id', $menu->getKey())
            ->get();
    }

    /**
     * What every item on this page is read from.
     *
     * menu_category_id is here because Eloquent needs it to attach an item to
     * the category that loaded it; dropping it would silently return empty
     * sections.
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
            'price_minor_units',
            'compare_at_price_minor_units',
            'is_service_request',
            'diet',
        ];
    }

    /**
     * Whether a category has anything a guest can actually read.
     *
     * A category with no items of its own and no subdivision holding any is an
     * empty heading, so it is left out entirely rather than rendered blank.
     */
    private function hasAnythingToRead(MenuCategory $category): bool
    {
        return $category->menuItems->isNotEmpty()
            || $category->children->contains(
                fn (MenuCategory $child): bool => $child->menuItems->isNotEmpty(),
            );
    }

    /**
     * The GST every price here is read against, and whether prices already include it.
     *
     * The rate rather than a computed amount: nothing has been ordered yet, so
     * there is nothing to compute — this is the line at the bottom of a menu
     * that says "prices exclude GST", which a guest is entitled to know before
     * they order rather than at the bill.
     *
     * @return array{rateBasisPoints: int, pricesIncludeTax: bool}
     */
    private function tax(Tenant $tenant): array
    {
        // The row the guest middleware has already read for the currency, not
        // a second query for the same tenant.
        $settings = $tenant->resolvedSettings();

        return [
            'rateBasisPoints' => $tenant->taxRateBasisPoints(),
            'pricesIncludeTax' => $settings instanceof TenantSetting && $settings->prices_include_tax,
        ];
    }

    /**
     * What a bill from this menu has added to it, in the order the tenant arranged.
     *
     * A share of the bill arrives as basis points and a fixed amount as minor
     * units, with the other null, so the app has nothing to work out but the
     * wording. A charge that is switched off, or limited to other menus, is not
     * sent at all.
     *
     * @return list<array{id: int, name: string, rateBasisPoints: int|null, amountMinorUnits: int|null}>
     */
    private function charges(Tenant $tenant, Menu $menu): array
    {
        return array_values(Charge::query()
            ->select(['id', 'name', 'rate_basis_points', 'amount_minor_units'])
            ->where('tenant_id', $tenant->getKey())
            ->active()
            ->forMenu($menu->getKey())
            ->inMenuOrder()
            ->get()
            ->map(fn (Charge $charge): array => [
                'id' => $charge->getKey(),
                'name' => $charge->name,
                'rateBasisPoints' => $charge->rate_basis_points,
                'amountMinorUnits' => $charge->amount_minor_units,
            ])
            ->all());
    }

    /**
     * One item, and the add-on groups a guest customises it with.
     *
     * @param  list<int>  $addOnGroupIds
     * @return array<string, mixed>
     */
    private function presentItem(MenuItem $item, array $addOnGroupIds): array
    {
        return [
            'id' => $item->getKey(),
            'name' => $item->name,
            'description' => $item->description,
            'priceMinorUnits' => $item->price_minor_units,
            // Null unless there is a real offer to show. hasComparePrice()
            // refuses one at or below the price being charged, so the app never
            // has to decide whether what it was handed is believable.
            'compareAtPriceMinorUnits' => $item->hasComparePrice() ? $item->compare_at_price_minor_units : null,
            'isServiceRequest' => $item->is_service_request,
            // Null for a service request, which carries no diet mark.
            'diet' => $item->diet?->value,
            // In the order the guest reads them; each id is one of the menu's
            // `addOnGroups`.
            'addOnGroupIds' => $addOnGroupIds,
        ];
    }
}
