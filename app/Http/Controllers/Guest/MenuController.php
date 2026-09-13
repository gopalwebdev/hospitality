<?php

namespace App\Http\Controllers\Guest;

use App\Enums\ItemAvailability;
use App\Enums\MenuBlock;
use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuComboItem;
use App\Models\MenuItem;
use App\Models\MenuItemAddition;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One of a tenant's menus, read at the table.
 *
 * The whole menu comes down together — the sections, their subdivisions, the
 * dishes in each and every dish's additions, plus the combos the menu leads
 * with — because that is one screen a guest scrolls, and fetching it in layers
 * would be a round trip per layer for one page. Both levels of section are rows
 * of menu_categories, so the subdivisions are simply the `children` of a
 * top-level one. Each query names the columns it
 * needs, so a long menu does not carry timestamps and foreign keys nobody
 * renders.
 *
 * Only what is actually orderable is sent: a hidden category, a hidden
 * sub-category, a sold-out dish and an addition that has run out are all absent
 * rather than greyed out, because a guest reading a menu on a phone should not
 * be scrolling past things they cannot have. The *reason* a dish is off never
 * reaches the guest either — App\Enums\ItemAvailability is for the kitchen, and
 * "temporarily unavailable" beside a dish is a worse read than the dish simply
 * not being listed.
 *
 * Prices go out as integers. Turning 24950 into ₹249.50 happens in the browser
 * — see resources/js/lib/money.ts — so the server never builds a string per row
 * and the result follows the guest's own language.
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

        $additions = fn ($additions) => $additions
            ->select(['id', 'menu_item_id', 'name', 'price_minor_units'])
            ->available()
            ->inMenuOrder();

        $dishes = fn ($items) => $items
            ->select($this->itemColumns())
            ->whereIn('availability', $orderable)
            ->with(['additions' => $additions])
            ->inMenuOrder();

        $sections = MenuCategory::query()
            // position is read as well as ordered by: it is what places a
            // section against the two rails in Menu::readingOrder().
            ->select(['id', 'parent_id', 'name', 'position'])
            ->where('menu_id', $menu->getKey())
            ->topLevel()
            ->where('is_active', true)
            ->with([
                // The dishes filed straight under the section, which are read
                // above its subdivisions: the general before the specific.
                'menuItems' => $dishes,

                'children' => fn ($children) => $children
                    ->select(['id', 'parent_id', 'name'])
                    ->where('is_active', true)
                    ->with(['menuItems' => $dishes])
                    ->inMenuOrder(),
            ])
            ->inMenuOrder()
            ->get()
            ->filter(fn (MenuCategory $category): bool => $this->hasAnythingToRead($category));

        // The dishes this menu leads with, above its sections. A separate query
        // rather than a flag read off the sections above: featuring has its own
        // order, and the same dish appears again under its section — a guest
        // scrolling down should find it where they expect it.
        $featured = MenuItem::query()
            ->select($this->itemColumns())
            ->where('tenant_id', $tenant->getKey())
            ->featuredOnMenu($menu->getKey())
            ->orderable()
            ->inFeaturedOrder()
            ->get();

        $this->attachAdditions($featured, $sections, $additions);

        $combos = MenuCombo::query()
            ->select(['id', 'name', 'description', 'price_minor_units', 'compare_at_price_minor_units'])
            ->where('menu_id', $menu->getKey())
            ->whereIn('availability', $orderable)
            ->with(['comboItems' => fn ($comboItems) => $comboItems
                ->select(['id', 'menu_combo_id', 'menu_item_id', 'quantity'])
                ->with(['menuItem' => fn ($item) => $item->select(['id', 'name', 'food_type'])])
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
            'featured' => $featured->map(
                fn (MenuItem $item): array => $this->presentItem($item),
            )->values()->all(),
            'combos' => $combos->map(fn (MenuCombo $combo): array => [
                'id' => $combo->getKey(),
                'name' => $combo->name,
                'description' => $combo->description,
                'priceMinorUnits' => $combo->price_minor_units,
                'compareAtPriceMinorUnits' => $combo->hasComparePrice() ? $combo->compare_at_price_minor_units : null,
                'contents' => $combo->comboItems->map(fn (MenuComboItem $comboItem): array => [
                    'id' => $comboItem->getKey(),
                    'name' => $comboItem->menuItem->name,
                    'foodType' => $comboItem->menuItem->food_type->value,
                    'quantity' => $comboItem->quantity,
                ])->values()->all(),
            ])->values()->all(),
            // Where the two rails sit among the sections is the tenant's
            // decision, dragged on the menu's arrangement screen, so the order
            // is worked out here rather than assumed by the app. Sections with
            // nothing to read have already been filtered out, so nothing in
            // this list points at a block that was not sent.
            'order' => array_map(
                fn (MenuBlock|MenuCategory $block): string|int => $block instanceof MenuBlock
                    ? $block->value
                    : $block->getKey(),
                $menu->readingOrder($sections),
            ),
            'sections' => $sections->map(fn (MenuCategory $category): array => [
                'id' => $category->getKey(),
                'name' => $category->name,
                'items' => $category->menuItems->map(
                    fn (MenuItem $item): array => $this->presentItem($item),
                )->values()->all(),
                'subSections' => $category->children
                    ->filter(fn (MenuCategory $child): bool => $child->menuItems->isNotEmpty())
                    ->map(fn (MenuCategory $child): array => [
                        'id' => $child->getKey(),
                        'name' => $child->name,
                        'items' => $child->menuItems->map(
                            fn (MenuItem $item): array => $this->presentItem($item),
                        )->values()->all(),
                    ])->values()->all(),
            ])->values()->all(),
            'charges' => $this->charges($tenant),
            'acceptingOrders' => $tenant->isAcceptingOrders(),
            'homeUrl' => route('guest.home', ['tenant' => $tenant->slug]),
        ]);
    }

    /**
     * Give each featured dish the additions its section has already loaded.
     *
     * A featured dish is nearly always also listed under its own section, where
     * its additions have just been read, so reading them again for the rail was
     * the same query twice. Only a featured dish that no section being shown
     * lists has its additions fetched here.
     *
     * @param  EloquentCollection<int, MenuItem>  $featured
     * @param  EloquentCollection<int, MenuCategory>  $sections
     */
    private function attachAdditions(EloquentCollection $featured, EloquentCollection $sections, Closure $additions): void
    {
        $listed = $sections
            ->flatMap(fn (MenuCategory $category): array => [
                ...$category->menuItems->all(),
                ...$category->children->flatMap(fn (MenuCategory $child): array => $child->menuItems->all())->all(),
            ])
            ->keyBy(fn (MenuItem $item): int => $item->getKey());

        $unlisted = $featured->reject(fn (MenuItem $item): bool => $listed->has($item->getKey()));

        if ($unlisted->isNotEmpty()) {
            $unlisted->load(['additions' => $additions]);
        }

        foreach ($featured as $item) {
            $inSection = $listed->get($item->getKey());

            if ($inSection instanceof MenuItem) {
                $item->setRelation('additions', $inSection->additions);
            }
        }
    }

    /**
     * What every dish on this page is read from.
     *
     * menu_category_id is here because Eloquent needs it to attach a dish to
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
            'food_type',
        ];
    }

    /**
     * Whether a category has anything a guest can actually read.
     *
     * A category with no dishes of its own and no subdivision holding any is an
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
     * What is added to what a guest orders, and whether the prices already
     * include it.
     *
     * The rate rather than a computed amount: nothing has been ordered yet, so
     * there is nothing to compute — this is the line at the bottom of a menu
     * that says "prices exclude GST", which a guest is entitled to know before
     * they order rather than at the bill.
     *
     * A charge that is switched off is sent as null rather than zero, so the
     * app has nothing to decide: it renders what it is given.
     *
     * @return array<string, mixed>
     */
    private function charges(Tenant $tenant): array
    {
        // The row the guest middleware has already read for the currency, not
        // a second query for the same tenant.
        $settings = $tenant->resolvedSettings();

        if (! $settings instanceof TenantSetting) {
            return [
                'taxRateBasisPoints' => $tenant->taxRateBasisPoints(),
                'pricesIncludeTax' => false,
                'serviceChargeBasisPoints' => null,
                'parcelChargeMinorUnits' => null,
            ];
        }

        return [
            'taxRateBasisPoints' => $settings->taxRateBasisPoints(),
            'pricesIncludeTax' => $settings->prices_include_tax,
            'serviceChargeBasisPoints' => $settings->service_charge_enabled
                ? $settings->service_charge_basis_points
                : null,
            'parcelChargeMinorUnits' => $settings->parcel_charge_enabled
                ? $settings->parcel_charge_minor_units
                : null,
        ];
    }

    /**
     * One dish and the extras it can be ordered with.
     *
     * @return array<string, mixed>
     */
    private function presentItem(MenuItem $item): array
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
            'foodType' => $item->food_type->value,
            'additions' => $item->additions->map(fn (MenuItemAddition $addition): array => [
                'id' => $addition->getKey(),
                'name' => $addition->name,
                // Zero is a real price, and the guest app says "Free" rather
                // than "+ ₹0.00" — which reads as a mistake.
                'priceMinorUnits' => $addition->price_minor_units,
            ])->values()->all(),
        ];
    }
}
