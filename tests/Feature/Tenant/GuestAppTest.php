<?php

use App\Enums\Appearance;
use App\Enums\Diet;
use App\Enums\ItemAvailability;
use App\Enums\Locale;
use App\Enums\MenuBlockType;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function guestUrl(Tenant $tenant): string
{
    return 'http://'.$tenant->slug.'.hospitality.test/';
}

function guestMenuUrl(Tenant $tenant, Menu $menu): string
{
    return 'http://'.$tenant->slug.'.hospitality.test/menus/'.$menu->getKey();
}

/**
 * A menu with one section holding one item, for the tenant given.
 *
 * @return array{Menu, MenuCategory, MenuItem}
 */
function seedOneItem(Tenant $tenant): array
{
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $item = MenuItem::factory()->inCategory($category)->create();

    return [$menu, $category, $item];
}

/*
|--------------------------------------------------------------------------
| The menu at the table
|--------------------------------------------------------------------------
*/

it('serves the menu with no sign-in', function (): void {
    $tenant = Tenant::factory()->create();
    [$menu, $category, $item] = seedOneItem($tenant);

    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertSee($item->name)
        ->assertSee($category->name);
});

it('leaves out what a guest cannot order', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $showing = MenuCategory::factory()->inMenu($menu)->create();
    $hidden = MenuCategory::factory()->inMenu($menu)->hidden()->create();

    $available = MenuItem::factory()->inCategory($showing)->create();
    $soldOut = MenuItem::factory()->inCategory($showing)->unavailable()->create();
    $inHidden = MenuItem::factory()->inCategory($hidden)->create();

    $extras = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    MenuItemAddOnGroup::factory()->linking($available, $extras)->create();

    $offered = MenuAddOnOption::factory()->inGroup($extras)->create();
    $runOut = MenuAddOnOption::factory()->inGroup($extras)->unavailable()->create();

    // A phone menu should not make someone scroll past things they cannot
    // have, so these are absent rather than greyed out.
    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertSee($available->name)
        ->assertSee($offered->name)
        ->assertDontSee($soldOut->name)
        ->assertDontSee($inHidden->name)
        ->assertDontSee($runOut->name);
});

it('takes a whole hidden menu down, sections and items with it', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->hidden()->create(['tenant_id' => $tenant->getKey()]);
    MenuItem::factory()->inCategory(MenuCategory::factory()->inMenu($menu)->create())->create();

    $this->get(guestMenuUrl($tenant, $menu))->assertNotFound();
});

it('shows only this tenant\'s menu', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    [$myMenu, , $mineItem] = seedOneItem($mine);
    [$theirMenu, , $theirsItem] = seedOneItem($theirs);

    $this->get(guestMenuUrl($mine, $myMenu))
        ->assertOk()
        ->assertSee($mineItem->name)
        ->assertDontSee($theirsItem->name);

    // The tenant arrives in the domain rather than the path, so scoped
    // bindings do not cover this: the controller has to refuse it by hand.
    $this->get(guestMenuUrl($mine, $theirMenu))->assertNotFound();
});

it('hides a tenant that is switched off', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => false]);
    [$menu] = seedOneItem($tenant);

    $this->get(guestUrl($tenant))->assertNotFound();
    $this->get(guestMenuUrl($tenant, $menu))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Light or dark belongs to the phone, not to the tenant
|--------------------------------------------------------------------------
*/

it('paints the guest app light until the phone says otherwise', function (): void {
    $tenant = Tenant::factory()->create();

    // In the first response's HTML, not an Inertia prop: React runs after the
    // paint, so a prop would show one shade and then correct itself.
    $this->get(guestUrl($tenant))
        ->assertOk()
        ->assertSee('"light"', escape: false);
});

it('paints the guest app dark when the phone has asked for dark', function (): void {
    $tenant = Tenant::factory()->create();

    // There is no per tenant default and no brand colour: the whole of the
    // theming is this cookie, which is unencrypted so the toggle in React and
    // the server read the same value.
    $this->withUnencryptedCookie('appearance', Appearance::Dark->value)
        ->get(guestUrl($tenant))
        ->assertOk()
        ->assertSee('"dark"', escape: false)
        ->assertDontSee('"light"', escape: false);
});

it('ignores a tampered appearance cookie rather than breaking the page', function (): void {
    $tenant = Tenant::factory()->create();

    // The cookie is unencrypted and therefore visitor-controlled.
    $this->withUnencryptedCookie('appearance', 'neon')
        ->get(guestUrl($tenant))
        ->assertOk()
        ->assertSee('"light"', escape: false);
});

it('offers no theme customisation to the tenant', function (): void {
    // Light and dark are the whole of it, so the columns that used to hold a
    // brand colour and a default are gone — see App\Enums\Appearance.
    expect(Schema::hasColumn('tenant_settings', 'theme_primary_color'))->toBeFalse()
        ->and(Schema::hasColumn('tenant_settings', 'theme_appearance'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The guest app loads its own entry, and never Filament
|--------------------------------------------------------------------------
*/

it('loads only its own entry and page', function (): void {
    // Built assets are hashed, so the guarantee has to be checked against the
    // manifest rather than against source paths, which only appear in dev.
    $manifestPath = public_path('build/manifest.json');

    if (! file_exists($manifestPath)) {
        $this->markTestSkipped('Run `npm run build` to check the built asset split.');
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $chunk = fn (string $source): string => basename($manifest[$source]['file']);

    $tenant = Tenant::factory()->create();

    $guest = $this->get(guestUrl($tenant))->assertOk()->getContent();

    expect($guest)->toContain($chunk('resources/js/guest.tsx'))
        ->and($guest)->toContain($chunk('resources/js/pages/guest/home.tsx'))
        ->and($guest)->not->toContain($chunk('resources/js/app.tsx'));
});

it('makes the guest app installable', function (): void {
    $tenant = Tenant::factory()->create();

    // A guest who comes back keeps the tenant on their home screen, so the
    // app links a manifest and registers a service worker on every page.
    $this->get(guestUrl($tenant))
        ->assertOk()
        ->assertSee('rel="manifest"', escape: false)
        ->assertSee(route('guest.manifest', ['tenant' => $tenant->slug]), escape: false)
        ->assertSee('serviceWorker', escape: false);
});

it('serves a manifest named for the tenant, scoped to its subdomain', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Spice Garden']);

    // Per tenant rather than a static file, so a phone with two tenants
    // installed shows two apps.
    $this->get(route('guest.manifest', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('name', 'Spice Garden')
        ->assertJsonPath('start_url', '/')
        ->assertJsonPath('scope', '/')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonCount(3, 'icons');
});

it('serves a service worker that never caches Inertia\'s own requests', function (): void {
    $tenant = Tenant::factory()->create();

    $response = $this->get(route('guest.service-worker', ['tenant' => $tenant->slug]))
        ->assertOk();

    // The same URL answers HTML to a navigation and JSON to Inertia, so only
    // navigations and hashed build assets are ever put in the cache.
    expect((string) $response->headers->get('Content-Type'))->toContain('javascript')
        ->and($response->getContent())
        ->toContain("request.mode === 'navigate'")
        ->toContain("url.pathname.startsWith('/build/')");
});

it('does not make a switched-off tenant installable', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => false]);

    $this->get(route('guest.manifest', ['tenant' => $tenant->slug]))->assertNotFound();
    $this->get(route('guest.service-worker', ['tenant' => $tenant->slug]))->assertNotFound();
});

it('leads a menu with the items the tenant featured', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    $second = MenuItem::factory()->inCategory($category)->create([
        'name' => [Locale::English->value => 'Rasmalai'],
        'is_featured' => true,
        'featured_position' => 2,
    ]);
    $first = MenuItem::factory()->inCategory($category)->create([
        'name' => [Locale::English->value => 'Paneer Tikka'],
        'is_featured' => true,
        'featured_position' => 1,
    ]);
    $plain = MenuItem::factory()->inCategory($category)->create();

    $this->get('http://'.$tenant->slug.'.hospitality.test/menus/'.$menu->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('featured', 2)
            ->where('featured.0.id', $first->getKey())
            ->where('featured.0.name', 'Paneer Tikka')
            ->where('featured.1.id', $second->getKey())
            // A featured item still appears under its own section, so a guest
            // scrolling down finds it where they expect it.
            ->has('sections.0.items', 3),
        );
});

it('paints a loader before the app it is waiting for', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    // React runs after the first paint, so without this a guest on a slow
    // connection is looking at a blank screen with nothing to say the page is
    // coming. It is CSS only and it hides itself the moment Inertia renders
    // into #app, which is what `#app:not(:empty)` is doing.
    foreach (['/', '/menus/'.$menu->getKey()] as $path) {
        $html = (string) $this->get('http://'.$tenant->slug.'.hospitality.test'.$path)
            ->assertOk()
            ->assertSee('id="boot-loader"', escape: false)
            ->getContent();

        // A general sibling, because the rule's own <style> block sits between
        // the app div and the spinner: `+` matched nothing and left the spinner
        // covering a page that had finished loading.
        expect($html)->toContain('#app:not(:empty) ~ #boot-loader');

        $appAt = strpos($html, '<div id="app"');
        $loaderAt = strpos($html, 'id="boot-loader"');

        expect($appAt)->not->toBeFalse()
            ->and($loaderAt)->not->toBeFalse()
            ->and($appAt)->toBeLessThan($loaderAt);
    }
});

it('reads a menu in the order the tenant arranged, rails and all', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $starters = MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $desserts = MenuCategory::factory()->inMenu($menu)->create(['position' => 2]);

    MenuItem::factory()->inCategory($starters)->create();
    MenuItem::factory()->inCategory($desserts)->create();

    // Untouched, a menu opens with its featured items and its combos. This one
    // has been dragged: the combos sit between the two sections and the
    // featured rail closes the menu.
    MenuBlock::factory()->onMenu($menu)->ofType(MenuBlockType::Combos)->create(['position' => 1]);
    MenuBlock::factory()->onMenu($menu)->ofType(MenuBlockType::Featured)->create(['position' => 3]);

    $this->get('http://'.$tenant->slug.'.hospitality.test/menus/'.$menu->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('order', [
                $starters->getKey(),
                'combos',
                $desserts->getKey(),
                'featured',
            ])
            ->etc(),
        );
});

it('opens a menu nobody has arranged with its featured items and its combos', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    MenuItem::factory()->inCategory($category)->create();

    // Neither rail has a row until it is dragged somewhere, and one without a
    // row reads level with the first category, rails first — so a menu nobody
    // has arranged reads the way every menu did before one could be.
    $this->get('http://'.$tenant->slug.'.hospitality.test/menus/'.$menu->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('order', ['featured', 'combos', $category->getKey()])
            ->etc(),
        );
});

it('leaves a sold-out item out of the featured row', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    MenuItem::factory()->inCategory($category)->create([
        'is_featured' => true,
        'availability' => ItemAvailability::OutOfStock,
    ]);
    $available = MenuItem::factory()->inCategory($category)->create(['is_featured' => true]);

    $this->get('http://'.$tenant->slug.'.hospitality.test/menus/'.$menu->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('featured', 1)
            ->where('featured.0.id', $available->getKey()),
        );
});

/*
|--------------------------------------------------------------------------
| Sub-categories, combos and the small print
|--------------------------------------------------------------------------
|
| The whole menu comes down in one response: categories, their subdivisions,
| the items in each, and the combos the menu leads with.
|
*/

it('nests a category\'s subdivisions under it, its own items first', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Biryani']]);

    $chicken = MenuCategory::factory()->under($category)->create([
        'name' => [Locale::English->value => 'Chicken'],
        'position' => 0,
    ]);
    $mutton = MenuCategory::factory()->under($category)->create([
        'name' => [Locale::English->value => 'Mutton'],
        'position' => 1,
    ]);

    $direct = MenuItem::factory()->inCategory($category)->create(['name' => [Locale::English->value => 'Plain Biryani']]);
    $inChicken = MenuItem::factory()->inCategory($chicken)->create();
    $inMutton = MenuItem::factory()->inCategory($mutton)->create();

    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('sections', 1)
            ->where('sections.0.name', 'Biryani')
            // The items filed straight under the category, and only those.
            ->has('sections.0.items', 1)
            ->where('sections.0.items.0.id', $direct->getKey())
            ->has('sections.0.subSections', 2)
            ->where('sections.0.subSections.0.name', 'Chicken')
            ->where('sections.0.subSections.0.items.0.id', $inChicken->getKey())
            ->where('sections.0.subSections.1.name', 'Mutton')
            ->where('sections.0.subSections.1.items.0.id', $inMutton->getKey()),
        );
});

it('leaves out a hidden sub-category and an empty category entirely', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $category = MenuCategory::factory()->inMenu($menu)->create();
    $hidden = MenuCategory::factory()->under($category)->hidden()->create();
    MenuItem::factory()->inCategory($hidden)->create();

    // A category whose only items are in a hidden subdivision has nothing
    // left to read, so it is not sent as an empty heading.
    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('sections', 0));
});

it('sends the combos a menu leads with, in the order they were arranged', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $burger = MenuItem::factory()->inCategory($category)->create(['name' => [Locale::English->value => 'Burger']]);

    $second = MenuCombo::factory()->onMenu($menu)->create([
        'name' => [Locale::English->value => 'Lunch Box'],
        'position' => 2,
    ]);
    $first = MenuCombo::factory()->onMenu($menu)->discounted()->create([
        'name' => [Locale::English->value => 'Burger Meal'],
        'position' => 1,
    ]);
    MenuComboItem::factory()->pairing($first, $burger)->quantity(2)->create();

    $soldOut = MenuCombo::factory()->onMenu($menu)->unavailable()->create();

    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('combos', 2)
            ->where('combos.0.id', $first->getKey())
            ->where('combos.0.name', 'Burger Meal')
            ->where('combos.0.compareAtPriceMinorUnits', $first->compare_at_price_minor_units)
            ->has('combos.0.contents', 1)
            ->where('combos.0.contents.0.name', 'Burger')
            ->where('combos.0.contents.0.quantity', 2)
            ->where('combos.1.id', $second->getKey()),
        )
        // A combo that cannot be ordered is absent rather than greyed out,
        // exactly as a sold-out item is — and there are only two here, so the
        // count above already says the third was left out.
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('combos.0.id', $first->getKey())
            ->where('combos.1.id', $second->getKey()));

    expect($soldOut->availability->isOrderable())->toBeFalse();
});

it('sends a struck-through price only when there is a real offer', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    $onOffer = MenuItem::factory()->inCategory($category)->create([
        'name' => [Locale::English->value => 'A Discounted'],
        'price_minor_units' => 29900,
        'compare_at_price_minor_units' => 36000,
        'position' => 0,
    ]);
    MenuItem::factory()->inCategory($category)->create([
        'name' => [Locale::English->value => 'B Plain'],
        'position' => 1,
    ]);

    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('sections.0.items.0.id', $onOffer->getKey())
            ->where('sections.0.items.0.compareAtPriceMinorUnits', 36000)
            // Null rather than the stored value, so the app never has to judge
            // whether what it was handed is believable.
            ->where('sections.0.items.1.compareAtPriceMinorUnits', null),
        );
});

it('sends how many of an item or a combo one order may hold', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    $pillow = MenuItem::factory()->inCategory($category)->service()->limitedPerOrder(2)->create(['position' => 0]);
    MenuItem::factory()->inCategory($category)->create(['position' => 1]);
    MenuCombo::factory()->onMenu($menu)->limitedPerOrder(4)->create();

    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('sections.0.items.0.id', $pillow->getKey())
            ->where('sections.0.items.0.maxQuantity', 2)
            // Null is no limit, and the app reads it that way.
            ->where('sections.0.items.1.maxQuantity', null)
            ->where('combos.0.maxQuantity', 4),
        );
});

it('tells a guest what the prices do not include before they order', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings->update([
        'tax_rate_basis_points' => 500,
        'prices_include_tax' => false,
    ]);

    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('tax.rateBasisPoints', 500)
            ->where('tax.pricesIncludeTax', false)
            // A tenant that levies nothing sends nothing to say.
            ->where('charges', []),
        );
});

it('sends only the charges a menu carries, switched on, in the order arranged', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $otherMenu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $service = Charge::factory()->percentage(1000)->onMenus($menu)->create([
        'name' => [Locale::English->value => 'Service Charge'],
        'position' => 0,
    ]);
    $packing = Charge::factory()->fixedAmount(2000)->onMenus($menu)->create([
        'name' => [Locale::English->value => 'Packing Charge'],
        'position' => 1,
    ]);

    // Limited to another menu, switched off, and another tenant's: none of
    // these belongs on this bill.
    Charge::factory()->fixedAmount(5000)->onMenus($otherMenu)->create();
    Charge::factory()->onMenus($menu)->inactive()->create();
    Charge::factory()->create();

    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('charges', 2)
            ->where('charges.0.id', $service->getKey())
            ->where('charges.0.name', 'Service Charge')
            // A share of the bill arrives as basis points, with no amount...
            ->where('charges.0.rateBasisPoints', 1000)
            ->where('charges.0.amountMinorUnits', null)
            // ...and a fixed sum as minor units, with no rate.
            ->where('charges.1.id', $packing->getKey())
            ->where('charges.1.rateBasisPoints', null)
            ->where('charges.1.amountMinorUnits', 2000),
        );
});

it('sends a service request with no diet mark, beside something to order', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    $pillow = MenuItem::factory()->inCategory($category)->service()->create(['position' => 0]);
    $water = MenuItem::factory()->inCategory($category)->create([
        'position' => 1,
        'diet' => Diet::Vegetarian,
    ]);

    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('sections.0.items.0.id', $pillow->getKey())
            ->where('sections.0.items.0.isServiceRequest', true)
            ->where('sections.0.items.0.diet', null)
            // Zero goes out as zero; the app names it complimentary.
            ->where('sections.0.items.0.priceMinorUnits', 0)
            ->where('sections.0.items.1.id', $water->getKey())
            ->where('sections.0.items.1.isServiceRequest', false)
            ->where('sections.0.items.1.diet', Diet::Vegetarian->value),
        );
});

it('says when a timed menu is being served, and when it is not', function (): void {
    $tenant = Tenant::factory()->create();
    $breakfast = Menu::factory()->servedBetween('07:00', '11:00')->create(['tenant_id' => $tenant->getKey()]);

    $this->travelTo(now()->setTime(9, 0));

    $this->get(guestMenuUrl($tenant, $breakfast))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // HH:MM whichever driver stored it, so the app has one shape.
            ->where('menu.servedFrom', '07:00')
            ->where('menu.servedUntil', '11:00')
            ->where('menu.isBeingServed', true),
        );

    $this->travelTo(now()->setTime(15, 0));

    // Still served, still readable — a guest looking for the breakfast card at
    // three should find it rather than conclude the tenant has none.
    $this->get(guestMenuUrl($tenant, $breakfast))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('menu.isBeingServed', false),
        );
});

it('sends no service window for a menu that has none', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('menu.servedFrom', null)
            ->where('menu.servedUntil', null)
            ->where('menu.isBeingServed', true),
        );
});

it('sends the menu in the order the tenant dragged it into', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    // Positions deliberately run against creation order and against
    // alphabetical, so only the dragged order can produce the result.
    $second = MenuCategory::factory()->inMenu($menu)->create([
        'name' => [Locale::English->value => 'A Second'],
        'position' => 1,
    ]);
    $first = MenuCategory::factory()->inMenu($menu)->create([
        'name' => [Locale::English->value => 'Z First'],
        'position' => 0,
    ]);

    $subSecond = MenuCategory::factory()->under($first)->create([
        'name' => [Locale::English->value => 'A Sub Second'],
        'position' => 1,
    ]);
    $subFirst = MenuCategory::factory()->under($first)->create([
        'name' => [Locale::English->value => 'Z Sub First'],
        'position' => 0,
    ]);

    $itemSecond = MenuItem::factory()->inCategory($first)->create([
        'name' => [Locale::English->value => 'A Second Item'],
        'position' => 1,
    ]);
    $itemFirst = MenuItem::factory()->inCategory($first)->create([
        'name' => [Locale::English->value => 'Z First Item'],
        'position' => 0,
    ]);

    MenuItem::factory()->inCategory($subFirst)->create();
    MenuItem::factory()->inCategory($subSecond)->create();
    // A section with nothing in it is left out as an empty heading, so the
    // second one needs an item to be in the payload at all.
    MenuItem::factory()->inCategory($second)->create();

    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // Sections in their dragged order.
            ->where('sections.0.name', 'Z First')
            ->where('sections.1.name', 'A Second')
            // A section's own items in theirs.
            ->where('sections.0.items.0.id', $itemFirst->getKey())
            ->where('sections.0.items.1.id', $itemSecond->getKey())
            // And its subdivisions in theirs.
            ->where('sections.0.subSections.0.name', 'Z Sub First')
            ->where('sections.0.subSections.1.name', 'A Sub Second'),
        );
});

/*
|--------------------------------------------------------------------------
| Add-on groups
|--------------------------------------------------------------------------
*/

it('sends each add-on group once, and each item the groups it offers in its own order', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $curry = MenuItem::factory()->inCategory($category)->create(['position' => 0]);
    $dal = MenuItem::factory()->inCategory($category)->create(['position' => 1]);

    $bread = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: true, max: 1)->create();
    $extras = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: false, max: 3)->create();

    $garlic = MenuAddOnOption::factory()->inGroup($bread)->asDefault()->create(['position' => 1, 'price_minor_units' => 2000]);
    $butter = MenuAddOnOption::factory()->inGroup($bread)->free()->create(['position' => 0]);
    MenuAddOnOption::factory()->inGroup($extras)->upTo(2)->create();

    // The curry reads its extras before its bread; the dal offers the bread alone.
    MenuItemAddOnGroup::factory()->linking($curry, $bread)->create(['position' => 1]);
    MenuItemAddOnGroup::factory()->linking($curry, $extras)->create(['position' => 0]);
    MenuItemAddOnGroup::factory()->linking($dal, $bread)->create(['position' => 0]);

    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('sections.0.items.0.addOnGroupIds', [$extras->getKey(), $bread->getKey()])
            ->where('sections.0.items.1.addOnGroupIds', [$bread->getKey()])
            // Offered on two items, sent once.
            ->has('addOnGroups', 2)
            ->where('addOnGroups', fn (Collection $groups): bool => $groups->firstWhere('id', $bread->getKey()) === [
                'id' => $bread->getKey(),
                'name' => $bread->name,
                'isRequired' => true,
                'maxSelections' => 1,
                // In the order they were dragged into.
                'options' => [
                    ['id' => $butter->getKey(), 'name' => $butter->name, 'priceMinorUnits' => 0, 'maxQuantity' => 1, 'isDefault' => false],
                    ['id' => $garlic->getKey(), 'name' => $garlic->name, 'priceMinorUnits' => 2000, 'maxQuantity' => 1, 'isDefault' => true],
                ],
            ])
            // Its option is capped at two, but the group does not allow the same
            // option twice, so a guest is sent a cap of one.
            ->where('addOnGroups', fn (Collection $groups): bool => ($groups->firstWhere('id', $extras->getKey())['options'][0]['maxQuantity'] ?? null) === 1)
            ->where('quoteUrl', guestMenuUrl($tenant, $menu).'/basket-quotes'),
        );
});

it('leaves out an item whose required group has nothing left to pick, and a group with nothing left to offer', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $curry = MenuItem::factory()->inCategory($category)->create(['position' => 0]);
    $dal = MenuItem::factory()->inCategory($category)->create(['position' => 1]);

    $bread = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: true, max: 1)->create();
    MenuAddOnOption::factory()->inGroup($bread)->unavailable()->create();

    $extras = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: false, max: 3)->create();
    MenuAddOnOption::factory()->inGroup($extras)->unavailable()->create();

    MenuItemAddOnGroup::factory()->linking($curry, $bread)->create();
    MenuItemAddOnGroup::factory()->linking($dal, $extras)->create();

    // A curry that needs a bread nobody can bring cannot be ordered, like a
    // sold-out item. The dal still can: its extras were only ever optional.
    $this->get(guestMenuUrl($tenant, $menu))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('sections.0.items', 1)
            ->where('sections.0.items.0.id', $dal->getKey())
            ->where('sections.0.items.0.addOnGroupIds', [])
            ->where('addOnGroups', []),
        );
});

it('builds the add-on groups in the same number of queries however many items offer them', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    $bread = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: true, max: 1)->create();
    MenuAddOnOption::factory()->count(2)->inGroup($bread)->create();
    $extras = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    MenuAddOnOption::factory()->count(3)->inGroup($extras)->create();

    $addItem = function () use ($category, $bread, $extras): MenuItem {
        $item = MenuItem::factory()->inCategory($category)->create();
        MenuItemAddOnGroup::factory()->linking($item, $bread)->create();
        MenuItemAddOnGroup::factory()->linking($item, $extras)->create();

        return $item;
    };

    $queriesToRender = function () use ($tenant, $menu): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(guestMenuUrl($tenant, $menu))->assertOk();

        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $addItem();
    $queriesToRender();
    $one = $queriesToRender();

    $addItem();
    $addItem();

    // And a third group, on one of them.
    $sides = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    MenuAddOnOption::factory()->inGroup($sides)->create();
    MenuItemAddOnGroup::factory()->linking($addItem(), $sides)->create();

    expect($queriesToRender())->toBe($one);
});
