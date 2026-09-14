<?php

use App\Actions\Menus\QuoteBasket;
use App\Models\Charge;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Pricing a basket kept on the phone
|--------------------------------------------------------------------------
|
| The guest app keeps its basket on the guest's phone and asks what it comes
| to. Everything in it is a claim, so each line is read again against the menu
| as it is now: priced if it still stands, flagged if it does not.
|
*/

function basketQuoteUrl(Tenant $tenant, Menu $menu): string
{
    return 'http://'.$tenant->slug.'.hospitality.test/menus/'.$menu->getKey().'/basket-quotes';
}

/**
 * A curry with a required bread and up to three extras, on a tenant adding 5% GST at the bill.
 *
 * @return array{tenant: Tenant, menu: Menu, category: MenuCategory, curry: MenuItem, bread: MenuAddOnGroup, extras: MenuAddOnGroup, butterNaan: MenuAddOnOption, garlicNaan: MenuAddOnOption, cheese: MenuAddOnOption, paneer: MenuAddOnOption}
 */
function seedCurryWithChoices(): array
{
    $tenant = Tenant::factory()->create();
    $tenant->settings->update(['tax_rate_basis_points' => 500, 'prices_include_tax' => false]);

    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $curry = MenuItem::factory()->inCategory($category)->create(['price_minor_units' => 28900]);

    $bread = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: true, max: 1)->create();
    $extras = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: false, max: 3)->allowingQuantities()->create();

    MenuItemAddOnGroup::factory()->linking($curry, $bread)->create(['position' => 0]);
    MenuItemAddOnGroup::factory()->linking($curry, $extras)->create(['position' => 1]);

    return [
        'tenant' => $tenant,
        'menu' => $menu,
        'category' => $category,
        'curry' => $curry,
        'bread' => $bread,
        'extras' => $extras,
        'butterNaan' => MenuAddOnOption::factory()->inGroup($bread)->free()->create(),
        'garlicNaan' => MenuAddOnOption::factory()->inGroup($bread)->create(['price_minor_units' => 2000]),
        'cheese' => MenuAddOnOption::factory()->inGroup($extras)->upTo(2)->create(['price_minor_units' => 4000]),
        'paneer' => MenuAddOnOption::factory()->inGroup($extras)->create(['price_minor_units' => 6000]),
    ];
}

/**
 * One line of a basket, as the guest app sends it.
 *
 * @param  list<array{MenuAddOnOption, int}>  $choices  each option and how many of it
 * @return array<string, mixed>
 */
function basketLine(string $key, MenuItem|MenuCombo $thing, int $quantity = 1, array $choices = []): array
{
    return [
        'key' => $key,
        'type' => $thing instanceof MenuCombo ? QuoteBasket::COMBO : QuoteBasket::ITEM,
        'id' => $thing->getKey(),
        'quantity' => $quantity,
        'choices' => array_map(
            fn (array $choice): array => ['optionId' => $choice[0]->getKey(), 'quantity' => $choice[1]],
            $choices,
        ),
    ];
}

it('prices each line with its choices, taxes the add-ons at the item\'s rate, and adds the charges this menu carries', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'curry' => $curry, 'garlicNaan' => $garlicNaan, 'cheese' => $cheese] = seedCurryWithChoices();

    $combo = MenuCombo::factory()->onMenu($menu)->create(['price_minor_units' => 59900, 'tax_rate_basis_points' => null]);

    $service = Charge::factory()->percentage(1000)->onMenus($menu)->create(['position' => 0]);
    $packing = Charge::factory()->ofTenant($tenant)->fixedAmount(2000)->onMenus($menu)->create(['position' => 1]);
    // Neither a charge on another menu nor one switched off is on this bill.
    Charge::factory()->ofTenant($tenant)->fixedAmount(5000)->onMenus(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))->create();
    Charge::factory()->fixedAmount(7000)->onMenus($menu)->inactive()->create();

    $this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => [
        basketLine('curry', $curry, quantity: 2, choices: [[$garlicNaan, 1], [$cheese, 2]]),
        basketLine('combo', $combo),
    ]])
        ->assertOk()
        ->assertExactJson([
            'lines' => [
                // ₹289.00 with a ₹20.00 garlic naan and two ₹40.00 cheese, twice.
                ['key' => 'curry', 'status' => QuoteBasket::OK, 'unitPriceMinorUnits' => 38900, 'totalMinorUnits' => 77800],
                ['key' => 'combo', 'status' => QuoteBasket::OK, 'unitPriceMinorUnits' => 59900, 'totalMinorUnits' => 59900],
            ],
            'subtotalMinorUnits' => 137700,
            // 5% of every part: an add-on is taxed at the rate of the item it is
            // added to, so the naan and the cheese pay the curry's 5%.
            'taxMinorUnits' => 2890 + 200 + 800 + 2995,
            'pricesIncludeTax' => false,
            'charges' => [
                ['id' => $service->getKey(), 'name' => $service->name, 'amountMinorUnits' => 13770],
                ['id' => $packing->getKey(), 'name' => $packing->name, 'amountMinorUnits' => 2000],
            ],
            'totalMinorUnits' => 137700 + 6885 + 13770 + 2000,
        ]);
});

it('flags a line whose choices break the rules of its groups, and prices the rest', function (): void {
    [
        'tenant' => $tenant, 'menu' => $menu, 'curry' => $curry, 'extras' => $extras,
        'butterNaan' => $butterNaan, 'garlicNaan' => $garlicNaan, 'cheese' => $cheese, 'paneer' => $paneer,
    ] = seedCurryWithChoices();

    $raita = MenuAddOnOption::factory()->inGroup($extras)->create();
    $runOut = MenuAddOnOption::factory()->inGroup($extras)->unavailable()->create();
    $notOffered = MenuAddOnOption::factory()->inGroup(MenuAddOnGroup::factory()->ofTenant($tenant)->create())->create();

    // Offered on the curry, and capped at three, but its group does not allow
    // the same option twice: one of each at most.
    $sides = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: false, max: 3)->create();
    MenuItemAddOnGroup::factory()->linking($curry, $sides)->create(['position' => 2]);
    $chutney = MenuAddOnOption::factory()->inGroup($sides)->upTo(3)->create();

    $response = $this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => [
        basketLine('meets-every-rule', $curry, choices: [[$butterNaan, 1], [$cheese, 2], [$paneer, 1]]),
        basketLine('no-bread', $curry),
        basketLine('two-breads', $curry, choices: [[$butterNaan, 1], [$garlicNaan, 1]]),
        basketLine('four-extras', $curry, choices: [[$butterNaan, 1], [$cheese, 2], [$paneer, 1], [$raita, 1]]),
        basketLine('three-cheese', $curry, choices: [[$butterNaan, 1], [$cheese, 3]]),
        basketLine('run-out', $curry, choices: [[$butterNaan, 1], [$runOut, 1]]),
        basketLine('not-offered-on-it', $curry, choices: [[$butterNaan, 1], [$notOffered, 1]]),
        basketLine('two-of-one-without-quantities', $curry, choices: [[$butterNaan, 1], [$chutney, 2]]),
    ]])->assertOk();

    expect(collect($response->json('lines'))->pluck('status', 'key')->all())->toBe([
        'meets-every-rule' => QuoteBasket::OK,
        'no-bread' => QuoteBasket::INVALID,
        'two-breads' => QuoteBasket::INVALID,
        'four-extras' => QuoteBasket::INVALID,
        'three-cheese' => QuoteBasket::INVALID,
        'run-out' => QuoteBasket::INVALID,
        'not-offered-on-it' => QuoteBasket::INVALID,
        'two-of-one-without-quantities' => QuoteBasket::INVALID,
    ])
        // A flagged line adds nothing until it is changed.
        ->and($response->json('subtotalMinorUnits'))->toBe(28900 + 8000 + 6000);
});

it('flags every line of an item or combo the basket holds more of than one order may, counting across its lines', function (): void {
    [
        'tenant' => $tenant, 'menu' => $menu, 'curry' => $curry,
        'butterNaan' => $butterNaan, 'garlicNaan' => $garlicNaan,
    ] = seedCurryWithChoices();

    $curry->update(['max_quantity' => 2]);
    $platter = MenuCombo::factory()->onMenu($menu)->limitedPerOrder(1)->create();

    $statuses = fn (array $lines): array => collect($this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => $lines])->assertOk()->json('lines'))
        ->pluck('status', 'key')
        ->all();

    // A curry with butter naan and one with garlic naan are two lines, and two
    // curries: as many as one order may hold.
    expect($statuses([
        basketLine('butter', $curry, choices: [[$butterNaan, 1]]),
        basketLine('garlic', $curry, choices: [[$garlicNaan, 1]]),
        basketLine('one-platter', $platter),
    ]))->toBe([
        'butter' => QuoteBasket::OK,
        'garlic' => QuoteBasket::OK,
        'one-platter' => QuoteBasket::OK,
    ])
        // A third curry on either line is one too many for both lines.
        ->and($statuses([
            basketLine('butter', $curry, quantity: 2, choices: [[$butterNaan, 1]]),
            basketLine('garlic', $curry, choices: [[$garlicNaan, 1]]),
            basketLine('two-platters', $platter, quantity: 2),
        ]))->toBe([
            'butter' => QuoteBasket::INVALID,
            'garlic' => QuoteBasket::INVALID,
            'two-platters' => QuoteBasket::INVALID,
        ]);
});

it('flags a line the menu can no longer offer, whatever was chosen', function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'category' => $category] = seedCurryWithChoices();

    $soldOut = MenuItem::factory()->inCategory($category)->unavailable()->create();
    $onAnotherMenu = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))->create())
        ->create();
    $anotherTenants = MenuItem::factory()->create();
    $comboGone = MenuCombo::factory()->onMenu($menu)->unavailable()->create();

    // A required group whose every option has run out takes its item off the menu.
    $dal = MenuItem::factory()->inCategory($category)->create();
    $rice = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: true, max: 1)->create();
    $noRiceLeft = MenuAddOnOption::factory()->inGroup($rice)->unavailable()->create();
    MenuItemAddOnGroup::factory()->linking($dal, $rice)->create();

    $response = $this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => [
        basketLine('sold-out', $soldOut),
        basketLine('on-another-menu', $onAnotherMenu),
        basketLine('another-tenants', $anotherTenants),
        basketLine('combo-gone', $comboGone),
        basketLine('nothing-left-to-choose', $dal, choices: [[$noRiceLeft, 1]]),
    ]])->assertOk();

    expect(collect($response->json('lines'))->pluck('status')->unique()->all())->toBe([QuoteBasket::UNAVAILABLE])
        ->and($response->json('subtotalMinorUnits'))->toBe(0)
        ->and($response->json('charges'))->toBe([]);
});

it('reports the GST already inside prices that include it, rather than adding it', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings->update(['tax_rate_basis_points' => 500, 'prices_include_tax' => true]);
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $item = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->create(['price_minor_units' => 10500]);

    // ₹105.00 including 5% is ₹100.00 and ₹5.00 of GST.
    $this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => [basketLine('tikka', $item)]])
        ->assertOk()
        ->assertJson([
            'subtotalMinorUnits' => 10500,
            'taxMinorUnits' => 500,
            'pricesIncludeTax' => true,
            'totalMinorUnits' => 10500,
        ]);
});

it('adds no charge to an empty basket', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings->update(['prices_include_tax' => false]);
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    Charge::factory()->ofTenant($tenant)->fixedAmount(2000)->create();

    $this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => []])
        ->assertOk()
        ->assertExactJson([
            'lines' => [],
            'subtotalMinorUnits' => 0,
            'taxMinorUnits' => 0,
            'pricesIncludeTax' => false,
            'charges' => [],
            'totalMinorUnits' => 0,
        ]);
});

it('prices nothing on a menu that is switched off, or on another tenant\'s', function (): void {
    $tenant = Tenant::factory()->create();
    $hidden = Menu::factory()->hidden()->create(['tenant_id' => $tenant->getKey()]);
    $theirs = Menu::factory()->create();

    $this->postJson(basketQuoteUrl($tenant, $hidden), ['lines' => []])->assertNotFound();
    $this->postJson(basketQuoteUrl($tenant, $theirs), ['lines' => []])->assertNotFound();
});

it('refuses a basket that is not the shape of one', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => [
        ['key' => 'voucher', 'type' => 'voucher', 'id' => 1, 'quantity' => 0, 'choices' => [['optionId' => 'extra', 'quantity' => 1]]],
    ]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines.0.type', 'lines.0.quantity', 'lines.0.choices.0.optionId']);
});

it('prices a basket in the same number of queries however many lines it has', function (): void {
    [
        'tenant' => $tenant, 'menu' => $menu, 'category' => $category, 'curry' => $curry,
        'bread' => $bread, 'extras' => $extras, 'butterNaan' => $butterNaan, 'cheese' => $cheese,
    ] = seedCurryWithChoices();

    $customised = fn (MenuItem $item): array => basketLine('item-'.$item->getKey(), $item, choices: [[$butterNaan, 1], [$cheese, 1]]);

    $queriesToPrice = function (array $lines) use ($tenant, $menu): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => $lines])->assertOk();

        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $more = MenuItem::factory()->count(4)->inCategory($category)->create()
        ->each(function (MenuItem $item) use ($bread, $extras): void {
            MenuItemAddOnGroup::factory()->linking($item, $bread)->create();
            MenuItemAddOnGroup::factory()->linking($item, $extras)->create();
        });

    $queriesToPrice([$customised($curry)]);
    $one = $queriesToPrice([$customised($curry)]);

    expect($queriesToPrice([$customised($curry), ...$more->map($customised)->all()]))->toBe($one);
});
