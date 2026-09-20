<?php

use App\Actions\Menus\QuoteBasket;
use App\Enums\GstTreatment;
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
    taxTenantAt($tenant, 500);

    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $curry = MenuItem::factory()->inCategory($category)->create(['price' => 28900]);

    $bread = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: true, max: 1)->create();
    $extras = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: false, max: 3)->create();

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
        'garlicNaan' => MenuAddOnOption::factory()->inGroup($bread)->create(['price' => 2000]),
        'cheese' => MenuAddOnOption::factory()->inGroup($extras)->upTo(2)->create(['price' => 4000]),
        'paneer' => MenuAddOnOption::factory()->inGroup($extras)->create(['price' => 6000]),
    ];
}

/**
 * A quote's GST as it comes back on the wire: levied in halves, so CGST and
 * SGST each carry half the rate and whatever each was worked out to be.
 *
 * @return array<string, mixed>
 */
function splitOf(int $cgst, int $sgst, int $rate = 500): array
{
    return [
        'treatment' => GstTreatment::IntraState->value,
        'cgstRate' => intdiv($rate, 2),
        'cgst' => $cgst,
        'sgstRate' => $rate - intdiv($rate, 2),
        'sgst' => $sgst,
        'igstRate' => 0,
        'igst' => 0,
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

    $combo = MenuCombo::factory()->onMenu($menu)->create(['price' => 59900, 'tax_rate' => null]);

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
                [
                    'key' => 'curry', 'status' => QuoteBasket::OK,
                    'unitPrice' => 38900, 'total' => 77800,
                    'taxableValue' => 77800,
                    'tax' => 2890 + 200 + 800,
                    // Rounded once per part, so a line's halves are its parts'
                    // halves added up rather than half of its total.
                    'taxParts' => splitOf(cgst: 1945, sgst: 1945),
                ],
                [
                    'key' => 'combo', 'status' => QuoteBasket::OK,
                    'unitPrice' => 59900, 'total' => 59900,
                    'taxableValue' => 59900,
                    'tax' => 2995,
                    // An odd total: the centre's half comes from its own rate
                    // and the state's is the remainder, so the two still add up.
                    'taxParts' => splitOf(cgst: 1498, sgst: 1497),
                ],
            ],
            'subtotal' => 137700,
            // 5% of every part: an add-on is taxed at the rate of the item it is
            // added to, so the naan and the cheese pay the curry's 5%. The
            // charges are taxed too — a service charge is part of the supply.
            'tax' => 2890 + 200 + 800 + 2995 + 689 + 100,
            'taxParts' => splitOf(cgst: 3837, sgst: 3837),
            'pricesIncludeTax' => false,
            'charges' => [
                [
                    'id' => $service->getKey(), 'name' => $service->name, 'amount' => 13770,
                    'taxableValue' => 13770, 'tax' => 689,
                    'taxParts' => splitOf(cgst: 344, sgst: 345),
                ],
                [
                    'id' => $packing->getKey(), 'name' => $packing->name, 'amount' => 2000,
                    'taxableValue' => 2000, 'tax' => 100,
                    'taxParts' => splitOf(cgst: 50, sgst: 50),
                ],
            ],
            'total' => 137700 + 6885 + 789 + 13770 + 2000,
            // Nothing here is counted, so nothing can run short.
            'shortages' => [],
        ]);
});

it('says what a basket would run short of, without refusing its lines or holding the stock', function (): void {
    [
        'tenant' => $tenant, 'menu' => $menu, 'curry' => $curry,
        'butterNaan' => $butterNaan, 'cheese' => $cheese,
    ] = seedCurryWithChoices();

    $curry->update(['stock_quantity' => 3]);
    $cheese->update(['stock_quantity' => 1]);

    // Two lines of curry are one count of three wanted, and two cheese on each
    // of the first line's two curries are four wanted of the one left.
    $response = $this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => [
        basketLine('two-with-cheese', $curry, quantity: 2, choices: [[$butterNaan, 1], [$cheese, 2]]),
        basketLine('one-plain', $curry, choices: [[$butterNaan, 1]]),
    ]])->assertOk();

    expect(collect($response->json('lines'))->pluck('status')->unique()->all())->toBe([QuoteBasket::OK])
        ->and($response->json('shortages'))->toBe([
            ['type' => 'option', 'id' => $cheese->getKey(), 'requested' => 4, 'available' => 1, 'lineKeys' => ['two-with-cheese']],
        ])
        ->and($curry->refresh()->stock_quantity)->toBe(3);
});

it('flags a line whose choices break the rules of its groups, and prices the rest', function (): void {
    [
        'tenant' => $tenant, 'menu' => $menu, 'curry' => $curry, 'extras' => $extras,
        'butterNaan' => $butterNaan, 'garlicNaan' => $garlicNaan, 'cheese' => $cheese, 'paneer' => $paneer,
    ] = seedCurryWithChoices();

    $raita = MenuAddOnOption::factory()->inGroup($extras)->create();
    $runOut = MenuAddOnOption::factory()->inGroup($extras)->unavailable()->create();
    $notOffered = MenuAddOnOption::factory()->inGroup(MenuAddOnGroup::factory()->ofTenant($tenant)->create())->create();

    // Offered on the curry and capped at three overall, but this option's own
    // cap is one: it may not be taken twice even though the group has room.
    $sides = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: false, max: 3)->create();
    MenuItemAddOnGroup::factory()->linking($curry, $sides)->create(['position' => 2]);
    $papadum = MenuAddOnOption::factory()->inGroup($sides)->create();

    $response = $this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => [
        basketLine('meets-every-rule', $curry, choices: [[$butterNaan, 1], [$cheese, 2], [$paneer, 1]]),
        basketLine('no-bread', $curry),
        basketLine('two-breads', $curry, choices: [[$butterNaan, 1], [$garlicNaan, 1]]),
        basketLine('four-extras', $curry, choices: [[$butterNaan, 1], [$cheese, 2], [$paneer, 1], [$raita, 1]]),
        basketLine('three-cheese', $curry, choices: [[$butterNaan, 1], [$cheese, 3]]),
        basketLine('run-out', $curry, choices: [[$butterNaan, 1], [$runOut, 1]]),
        basketLine('not-offered-on-it', $curry, choices: [[$butterNaan, 1], [$notOffered, 1]]),
        basketLine('two-papadum', $curry, choices: [[$butterNaan, 1], [$papadum, 2]]),
    ]])->assertOk();

    expect(collect($response->json('lines'))->pluck('status', 'key')->all())->toBe([
        'meets-every-rule' => QuoteBasket::OK,
        'no-bread' => QuoteBasket::INVALID,
        'two-breads' => QuoteBasket::INVALID,
        'four-extras' => QuoteBasket::INVALID,
        'three-cheese' => QuoteBasket::INVALID,
        'run-out' => QuoteBasket::INVALID,
        'not-offered-on-it' => QuoteBasket::INVALID,
        'two-papadum' => QuoteBasket::INVALID,
    ])
        // A flagged line adds nothing until it is changed.
        ->and($response->json('subtotal'))->toBe(28900 + 8000 + 6000);
});

it("enforces an item's own cap on a group's picks, tighter than the group's own", function (): void {
    ['tenant' => $tenant, 'menu' => $menu, 'category' => $category] = seedCurryWithChoices();

    $dal = MenuItem::factory()->inCategory($category)->create(['price' => 19900]);
    $extras = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: false, max: 3)->create();
    $cheese = MenuAddOnOption::factory()->inGroup($extras)->upTo(2)->create(['price' => 4000]);
    $paneer = MenuAddOnOption::factory()->inGroup($extras)->create(['price' => 6000]);

    // The group's own library allows up to three; this item allows only one.
    MenuItemAddOnGroup::factory()->linking($dal, $extras)->capping(1)->create();

    $response = $this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => [
        basketLine('one-extra', $dal, choices: [[$cheese, 1]]),
        basketLine('two-extras', $dal, choices: [[$cheese, 1], [$paneer, 1]]),
    ]])->assertOk();

    expect(collect($response->json('lines'))->pluck('status', 'key')->all())->toBe([
        'one-extra' => QuoteBasket::OK,
        'two-extras' => QuoteBasket::INVALID,
    ]);
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
        ->and($response->json('subtotal'))->toBe(0)
        ->and($response->json('charges'))->toBe([]);
});

it('reports the GST already inside prices that include it, rather than adding it', function (): void {
    $tenant = Tenant::factory()->create();
    taxTenantAt($tenant, 500, pricesIncludeTax: true);
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $item = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->create(['price' => 10500]);

    // ₹105.00 including 5% is ₹100.00 and ₹5.00 of GST.
    $this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => [basketLine('tikka', $item)]])
        ->assertOk()
        ->assertJson([
            'subtotal' => 10500,
            'tax' => 500,
            'pricesIncludeTax' => true,
            'total' => 10500,
        ]);
});

it('taxes every line at the tenant rate once settings override the items own', function (): void {
    $tenant = Tenant::factory()->create();
    taxTenantAt($tenant, 500);
    $tenant->settings->update(['tax_overrides_item_rates' => true]);

    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $item = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->taxedAt(2800)
        ->create(['price' => 10000]);

    // The item says 28%, the tenant says everything it sells is 5%, and the
    // tenant wins — ₹100.00 plus ₹5.00 rather than plus ₹28.00.
    $this->postJson(basketQuoteUrl($tenant, $menu), ['lines' => [basketLine('tikka', $item)]])
        ->assertOk()
        ->assertJson([
            'subtotal' => 10000,
            'tax' => 500,
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
            'subtotal' => 0,
            'tax' => 0,
            'taxParts' => splitOf(cgst: 0, sgst: 0, rate: 0),
            'pricesIncludeTax' => false,
            'charges' => [],
            'total' => 0,
            'shortages' => [],
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
