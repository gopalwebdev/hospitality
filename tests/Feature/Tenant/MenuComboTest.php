<?php

use App\Enums\ItemAvailability;
use App\Enums\Locale;
use App\Enums\Role as RoleEnum;
use App\Filament\Restaurant\Resources\Menus\Pages\ManageMenuCombos;
use App\Filament\Restaurant\Resources\Menus\Schemas\MenuComboForm;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuComboItem;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Find a combo by the English half of its translated name.
 */
function comboNamed(string $name): MenuCombo
{
    return MenuCombo::query()
        ->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, $name)
        ->sole();
}

/**
 * Open the combos table on one menu's page.
 */
function combosOf(Menu $menu): Testable
{
    return Livewire::test(ManageMenuCombos::class, ['record' => $menu->getKey()]);
}

/**
 * A dish on the given menu, in a category of its own.
 */
function dishOn(Menu $menu): MenuItem
{
    return MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->create();
}

/*
|--------------------------------------------------------------------------
| Building a combo
|--------------------------------------------------------------------------
|
| A bundle sold at one price, arranged in a row of its own beside the featured
| dishes. Its price is its own, never derived from what is inside it.
|
*/

it('creates a combo on the menu with the dishes it contains', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $burger = dishOn($menu);
    $fries = dishOn($menu);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    combosOf($menu)
        ->callAction(TestAction::make('create')->table(), [
            'name' => [Locale::English->value => 'Burger Meal', Locale::Tamil->value => 'பர்கர் உணவு'],
            'description' => [Locale::English->value => 'Burger, fries and a drink.'],
            'price' => '299',
            'compare_at_price' => '360',
            'availability' => ItemAvailability::Available->value,
            'comboItems' => [
                ['menu_item_id' => $burger->getKey(), 'quantity' => 1],
                ['menu_item_id' => $fries->getKey(), 'quantity' => 2],
            ],
        ])
        ->assertHasNoActionErrors();

    $combo = comboNamed('Burger Meal');

    expect($combo->tenant_id)->toBe($restaurant->getKey())
        ->and($combo->menu_id)->toBe($menu->getKey())
        // ₹299.00 is 29900 paise, exactly. No float reaches the column.
        ->and($combo->price_minor_units)->toBe(29900)
        ->and($combo->compare_at_price_minor_units)->toBe(36000)
        ->and($combo->getTranslation('name', Locale::Tamil->value))->toBe('பர்கர் உணவு')
        ->and($combo->comboItems()->count())->toBe(2)
        ->and($combo->comboItems()->where('menu_item_id', $fries->getKey())->value('quantity'))->toBe(2);
});

it('leaves the compare-at price empty rather than storing a zero', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    combosOf($menu)
        ->callAction(TestAction::make('create')->table(), [
            'name' => [Locale::English->value => 'Lunch Box'],
            'price' => '150',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    // Null is "not on offer"; zero would be a price of nothing.
    expect(comboNamed('Lunch Box')->compare_at_price_minor_units)->toBeNull();
});

it('refuses a compare-at price that is not above what is charged', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // A "was" price at or below the real one advertises a discount that does
    // not exist, which is the one way this field can mislead a guest.
    combosOf($menu)
        ->callAction(TestAction::make('create')->table(), [
            'name' => [Locale::English->value => 'Lunch Box'],
            'price' => '150',
            'compare_at_price' => '150',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasActionErrors(['compare_at_price']);
});

it('refuses a combo name the same menu already uses', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    MenuCombo::factory()->onMenu($menu)->create(['name' => [Locale::English->value => 'Family Feast']]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    combosOf($menu)
        ->callAction(TestAction::make('create')->table(), [
            'name' => [Locale::English->value => 'Family Feast'],
            'price' => '999',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasActionErrors(['name.'.Locale::English->value]);
});

it('offers only this menu\'s dishes as combo contents', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $otherMenu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $mine = dishOn($menu);
    $elsewhere = dishOn($otherMenu);

    $theirs = Restaurant::factory()->create();
    $theirDish = dishOn(Menu::factory()->create(['tenant_id' => $theirs->getKey()]));

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // The same call the select inside the repeater makes. A combo may only
    // contain dishes from the menu it is offered on.
    $offered = array_keys(MenuComboForm::dishOptions($menu->getKey()));

    expect($offered)->toContain($mine->getKey())
        ->and($offered)->not->toContain($elsewhere->getKey())
        ->and($offered)->not->toContain($theirDish->getKey());
});

it('refuses at the database a combo containing another restaurant\'s dish', function (): void {
    $mine = Restaurant::factory()->create();
    $combo = MenuCombo::factory()->onMenu(Menu::factory()->create(['tenant_id' => $mine->getKey()]))->create();

    $theirs = Restaurant::factory()->create();
    $theirDish = dishOn(Menu::factory()->create(['tenant_id' => $theirs->getKey()]));

    expect(fn () => DB::table('menu_combo_items')->insert([
        'tenant_id' => $mine->getKey(),
        'menu_combo_id' => $combo->getKey(),
        'menu_item_id' => $theirDish->getKey(),
        'quantity' => 1,
        'position' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses the same dish twice in one combo', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $combo = MenuCombo::factory()->onMenu($menu)->create();
    $dish = dishOn($menu);

    MenuComboItem::factory()->pairing($combo, $dish)->create();

    // A dish appears once, with a quantity — two rows would show as a
    // duplicate line to the guest.
    expect(fn () => MenuComboItem::factory()->pairing($combo, $dish)->create())
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Pricing
|--------------------------------------------------------------------------
*/

it('prices a combo on its own rather than from its contents', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $combo = MenuCombo::factory()->onMenu($menu)->create(['price_minor_units' => 29900]);

    $burger = dishOn($menu);
    $burger->update(['price_minor_units' => 18000]);
    $fries = dishOn($menu);
    $fries->update(['price_minor_units' => 9000]);

    MenuComboItem::factory()->pairing($combo, $burger)->create();
    MenuComboItem::factory()->pairing($combo, $fries)->quantity(2)->create();

    $combo->load('comboItems.menuItem');

    // The whole point of a combo is that it costs less than its parts, so the
    // contents total is only ever shown beside the price, never used as it.
    expect($combo->contentsPriceMinorUnits())->toBe(36000)
        ->and($combo->price_minor_units)->toBe(29900);
});

it('falls back to the restaurant\'s GST rate, and overrides it when told', function (): void {
    $restaurant = Restaurant::factory()->create();
    $restaurant->settings->update(['tax_rate_basis_points' => 1800]);
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $following = MenuCombo::factory()->onMenu($menu)->create();
    $overriding = MenuCombo::factory()->onMenu($menu)->taxedAt(1200)->create();

    expect($following->taxRateBasisPoints())->toBe(1800)
        ->and($overriding->taxRateBasisPoints())->toBe(1200);
});

/*
|--------------------------------------------------------------------------
| On the menu
|--------------------------------------------------------------------------
*/

it('rearranges combos by dragging them', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $first = MenuCombo::factory()->onMenu($menu)->create(['position' => 0]);
    $second = MenuCombo::factory()->onMenu($menu)->create(['position' => 1]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    combosOf($menu)->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($second->refresh()->position)->toBeLessThan($first->refresh()->position);
});

it('keeps rearranging combos away from someone who may only read the menu', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $first = MenuCombo::factory()->onMenu($menu)->create(['position' => 0]);
    $second = MenuCombo::factory()->onMenu($menu)->create(['position' => 1]);

    enterRestaurantPanel($restaurant, RoleEnum::Staff);

    combosOf($menu)->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($first->refresh()->position)->toBe(0)
        ->and($second->refresh()->position)->toBe(1);
});

it('shows only this menu\'s combos', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $otherMenu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $mine = MenuCombo::factory()->onMenu($menu)->create();
    $elsewhere = MenuCombo::factory()->onMenu($otherMenu)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    combosOf($menu)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$elsewhere]);
});

it('leaves the dishes alone when a combo is deleted', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $combo = MenuCombo::factory()->onMenu($menu)->create();
    $dish = dishOn($menu);

    MenuComboItem::factory()->pairing($combo, $dish)->create();

    $combo->delete();

    expect(MenuItem::query()->withoutGlobalScopes()->find($dish->getKey()))->not->toBeNull()
        ->and(MenuComboItem::query()->count())->toBe(0);
});

it('takes a dish out of every combo when it is deleted', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $combo = MenuCombo::factory()->onMenu($menu)->create();
    $dish = dishOn($menu);

    MenuComboItem::factory()->pairing($combo, $dish)->create();

    $dish->delete();

    // A combo still advertising a dish that no longer exists is worse than one
    // that is a line shorter.
    expect(MenuComboItem::query()->count())->toBe(0)
        ->and(MenuCombo::query()->withoutGlobalScopes()->find($combo->getKey()))->not->toBeNull();
});

it('takes a menu\'s combos down with it', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $combo = MenuCombo::factory()->onMenu($menu)->create();

    $menu->delete();

    expect(MenuCombo::query()->withoutGlobalScopes()->find($combo->getKey()))->toBeNull();
});

it('keeps an unavailable combo off the guest\'s menu', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $offered = MenuCombo::factory()->onMenu($menu)->create();
    $soldOut = MenuCombo::factory()->onMenu($menu)->unavailable()->create();

    $orderable = MenuCombo::query()->orderable()->pluck('id')->all();

    expect($orderable)->toContain($offered->getKey())
        ->not->toContain($soldOut->getKey());
});

it('takes a hidden menu\'s combos down with it', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->hidden()->create(['tenant_id' => $restaurant->getKey()]);
    MenuCombo::factory()->onMenu($menu)->create();

    expect(MenuCombo::query()->orderable()->count())->toBe(0);
});
