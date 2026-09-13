<?php

use App\Enums\Currency;
use App\Enums\FoodType;
use App\Enums\ItemAvailability;
use App\Enums\Locale;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Restaurant\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Restaurant\Resources\Menus\Pages\ArrangeMenu;
use App\Filament\Restaurant\Resources\Menus\Pages\ListMenus;
use App\Filament\Restaurant\Resources\Menus\Pages\ManageMenuFeaturedItems;
use App\Filament\Schemas\PricingFields;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemAddition;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Find a record by the English half of its translated name.
 *
 * Every unique index in the schema is built on this value, and so is every
 * lookup here — matching the whole JSON document would only find a record whose
 * every language happened to agree.
 *
 * @param  class-string<Menu|MenuCategory|MenuItem|MenuItemAddition>  $model
 */
function byEnglishName(string $model, string $name): Menu|MenuCategory|MenuItem|MenuItemAddition
{
    return $model::query()
        ->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, $name)
        ->sole();
}

/*
|--------------------------------------------------------------------------
| Who may work on the menu
|--------------------------------------------------------------------------
|
| menu.view opens the pages and staff hold it; menu.manage is what changes
| anything, and only a restaurant admin has it.
|
*/

it('lets a restaurant admin manage the menu', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $item = MenuItem::factory()->inCategory(MenuCategory::factory()->inMenu($menu)->create())->create();

    $admin = enterRestaurantPanel($restaurant, RoleEnum::Admin);

    expect($admin->can('viewAny', MenuItem::class))->toBeTrue()
        ->and($admin->can('create', MenuItem::class))->toBeTrue()
        ->and($admin->can('update', $item))->toBeTrue()
        ->and($admin->can('delete', $item))->toBeTrue()
        ->and($admin->can('viewAny', Menu::class))->toBeTrue()
        ->and($admin->can('create', Menu::class))->toBeTrue()
        ->and($admin->can('reorder', Menu::class))->toBeTrue();
});

it('lets staff read the menu but not change it', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $item = MenuItem::factory()->inCategory(MenuCategory::factory()->inMenu($menu)->create())->create();

    $staff = enterRestaurantPanel($restaurant, RoleEnum::Staff);

    expect($staff->can(PermissionEnum::MenuView->value))->toBeTrue()
        ->and($staff->can('viewAny', MenuItem::class))->toBeTrue()
        ->and($staff->can('viewAny', Menu::class))->toBeTrue()
        ->and($staff->can('create', MenuItem::class))->toBeFalse()
        ->and($staff->can('create', Menu::class))->toBeFalse()
        ->and($staff->can('update', $item))->toBeFalse()
        ->and($staff->can('delete', $item))->toBeFalse();
});

it('keeps someone with no role off the menu pages', function (): void {
    $restaurant = Restaurant::factory()->create();
    $nobody = User::factory()->ofRestaurant($restaurant)->create();

    expect($nobody->can('viewAny', MenuItem::class))->toBeFalse()
        ->and($nobody->can('viewAny', MenuCategory::class))->toBeFalse()
        ->and($nobody->can('viewAny', Menu::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Menus
|--------------------------------------------------------------------------
*/

it('creates a menu against the restaurant whose panel it is', function (): void {
    $restaurant = Restaurant::factory()->create();
    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListMenus::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Dinner', Locale::Tamil->value => 'இரவு உணவு'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $menu = byEnglishName(Menu::class, 'Dinner');

    expect($menu->tenant_id)->toBe($restaurant->getKey())
        ->and($menu->is_active)->toBeTrue()
        ->and($menu->getTranslations('name'))->toBe([
            Locale::English->value => 'Dinner',
            Locale::Tamil->value => 'இரவு உணவு',
        ]);
});

it('lets a menu be created in English alone', function (): void {
    $restaurant = Restaurant::factory()->create();
    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // A restaurant that has not translated its menu yet is the normal state on
    // day one, so only the fallback language is required.
    Livewire::test(ListMenus::class)
        ->callAction('create', ['name' => [Locale::English->value => 'Lunch'], 'position' => 0, 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(byEnglishName(Menu::class, 'Lunch')->name)->toBe('Lunch');
});

it('requires the fallback language on a menu', function (): void {
    $restaurant = Restaurant::factory()->create();
    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // Tamil alone would leave an English-reading guest with a blank heading,
    // and there would be nothing for the unique index to be built on.
    Livewire::test(ListMenus::class)
        ->callAction('create', ['name' => [Locale::Tamil->value => 'இரவு உணவு'], 'position' => 0, 'is_active' => true])
        ->assertHasActionErrors(['name.'.Locale::English->value]);
});

it('refuses a menu name the restaurant already uses', function (): void {
    $restaurant = Restaurant::factory()->create();
    Menu::factory()->create([
        'tenant_id' => $restaurant->getKey(),
        'name' => [Locale::English->value => 'Dinner'],
    ]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListMenus::class)
        ->callAction('create', ['name' => [Locale::English->value => 'Dinner'], 'position' => 0, 'is_active' => true])
        ->assertHasActionErrors(['name.'.Locale::English->value]);
});

it('takes a menu\'s sections and their dishes with it when it is deleted', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $item = MenuItem::factory()->inCategory($category)->create();
    $addition = MenuItemAddition::factory()->onItem($item)->create();

    $menu->delete();

    expect(MenuCategory::query()->whereKey($category->getKey())->exists())->toBeFalse()
        ->and(MenuItem::query()->whereKey($item->getKey())->exists())->toBeFalse()
        ->and(MenuItemAddition::query()->whereKey($addition->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Sections
|--------------------------------------------------------------------------
*/

it('creates a section on the menu it was filed under', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::English->value => 'Starters', Locale::Tamil->value => 'தொடக்கங்கள்'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $category = byEnglishName(MenuCategory::class, 'Starters');

    expect($category->tenant_id)->toBe($restaurant->getKey())
        ->and($category->menu_id)->toBe($menu->getKey())
        ->and($category->is_active)->toBeTrue()
        ->and($category->getTranslation('name', Locale::Tamil->value))->toBe('தொடக்கங்கள்');
});

it('refuses a section name the same menu already uses', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Starters']]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::English->value => 'Starters'],
            'is_active' => true,
        ])
        ->assertHasActionErrors(['name.'.Locale::English->value]);
});

it('lets a lunch and a dinner menu each have their own Starters', function (): void {
    $restaurant = Restaurant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    MenuCategory::factory()->inMenu($lunch)->create(['name' => [Locale::English->value => 'Starters']]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // Uniqueness moved from the restaurant to the menu when menus arrived, and
    // this is the case that motivated it.
    Livewire::test(ArrangeMenu::class, ['record' => $dinner->getKey()])
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::English->value => 'Starters'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    expect(MenuCategory::query()->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, 'Starters')
        ->count())->toBe(2);
});

it('lets two restaurants both have a section of the same name', function (): void {
    $other = Restaurant::factory()->create();
    MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $other->getKey()]))
        ->create(['name' => [Locale::English->value => 'Starters']]);

    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::English->value => 'Starters'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    // The panel is booted, so MenuCategory carries a tenancy scope. Counting
    // across restaurants has to step outside it deliberately.
    expect(MenuCategory::query()->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, 'Starters')
        ->count())->toBe(2);
});

it('refuses at the database to file a section under another restaurant\'s menu', function (): void {
    $mine = Restaurant::factory()->create();
    $theirs = Restaurant::factory()->create();
    $theirMenu = Menu::factory()->create(['tenant_id' => $theirs->getKey()]);

    expect(fn () => DB::table('menu_categories')->insert([
        'tenant_id' => $mine->getKey(),
        'menu_id' => $theirMenu->getKey(),
        'name' => json_encode([Locale::English->value => 'Smuggled'], JSON_THROW_ON_ERROR),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('reads a menu as one tree without grouping or ordering on a translated column', function (): void {
    // Grouping is the trap this replaced. menu_categories.name is a translated
    // column, and a Filament group orders by the attribute it groups on — which
    // 500'd while the column was plain json, which has no ordering operator.
    //
    // The arrangement has no grouping at all: it is one list built in reading
    // order, so the nesting survives drag mode, which a grouped table's never
    // did (Filament turns grouping off while reordering).
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $starters = MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $chicken = MenuCategory::factory()->under($starters)->create(['position' => 0]);
    $dish = MenuItem::factory()->inCategory($starters)->create(['position' => 0]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    $table = Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->assertOk()
        ->instance()
        ->getTable();

    expect($table->getDefaultGroup())->toBeNull()
        // A section, then its own dishes, then its subdivisions: the order a
        // guest reads the menu in.
        ->and(array_keys($table->getRecords()->all()))->toBe([
            'featured',
            'combos',
            'category-'.$starters->getKey(),
            'item-'.$dish->getKey(),
            'category-'.$chicken->getKey(),
        ]);
});

/*
|--------------------------------------------------------------------------
| Dishes, and the money they are priced in
|--------------------------------------------------------------------------
*/

it('stores a typed price as an exact integer count of minor units', function (): void {
    $restaurant = Restaurant::factory()->create();
    $restaurant->settings->update(['currency' => Currency::IndianRupee]);
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Paneer Tikka'],
            'menu_category_id' => $category->getKey(),
            'food_type' => FoodType::Vegetarian->value,
            'price' => '249.50',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    $item = byEnglishName(MenuItem::class, 'Paneer Tikka');

    // ₹249.50 is 24950 paise, exactly. No float ever reaches the column.
    expect($item->price_minor_units)->toBe(24950)
        ->and($item->price_minor_units)->toBeInt()
        ->and($item->formattedPrice())->toBe('₹249.50')
        ->and($item->tenant_id)->toBe($restaurant->getKey());
});

it('round-trips a price through the edit form without drift', function (): void {
    $restaurant = Restaurant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();
    $item = MenuItem::factory()->inCategory($category)->create(['price_minor_units' => 24950]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($item), [
            'name' => $item->getTranslations('name'),
            'menu_category_id' => $category->getKey(),
            'food_type' => $item->food_type->value,
            'price' => '249.50',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    expect($item->refresh()->price_minor_units)->toBe(24950);
});

it('fills the edit form with every language, not just the current one', function (): void {
    $restaurant = Restaurant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();
    $item = MenuItem::factory()->inCategory($category)->create([
        'name' => [Locale::English->value => 'Paneer Tikka', Locale::Tamil->value => 'பன்னீர் டிக்கா'],
    ]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // Spatie hands back one language for a translated attribute; the form edits
    // them all, so the whole document has to be put back before filling.
    Livewire::test(ListMenuItems::class)
        ->mountAction(TestAction::make('edit')->table($item))
        ->assertActionDataSet([
            'name' => [Locale::English->value => 'Paneer Tikka', Locale::Tamil->value => 'பன்னீர் டிக்கா'],
        ]);
});

it('formats a price in rupees', function (): void {
    $restaurant = Restaurant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();
    $item = MenuItem::factory()->inCategory($category)->create(['price_minor_units' => 1250]);

    expect($item->formattedPrice())->toBe('₹12.50');
});

it('offers no grouping control on the dishes page', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    MenuItem::factory()->inCategory(MenuCategory::factory()->inMenu($menu)->create())->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // Grouping was removed rather than fixed: Filament turns it off while
    // reordering anyway, and a group header only breaks when its title changes
    // from the previous row — so two sections of the same name on different
    // menus fragmented into repeated headers. Filters replaced it.
    $table = Livewire::test(ListMenuItems::class)->assertOk()->instance()->getTable();

    expect($table->getGroups())->toBe([])
        ->and($table->getDefaultGroup())->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Additions
|--------------------------------------------------------------------------
*/

it('saves a dish\'s additions in the same save as the dish', function (): void {
    $restaurant = Restaurant::factory()->create();
    $restaurant->settings->update(['currency' => Currency::IndianRupee]);
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Paneer Tikka'],
            'menu_category_id' => $category->getKey(),
            'food_type' => FoodType::Vegetarian->value,
            'price' => '249.50',
            'availability' => ItemAvailability::Available->value,
            'additions' => [
                [
                    'name' => [Locale::English->value => 'Extra paneer', Locale::Tamil->value => 'கூடுதல் பன்னீர்'],
                    'price' => '50',
                    'is_available' => true,
                ],
                [
                    'name' => [Locale::English->value => 'Less spicy'],
                    'price' => '0',
                    'is_available' => true,
                ],
            ],
        ])
        ->assertHasNoActionErrors();

    $item = byEnglishName(MenuItem::class, 'Paneer Tikka');
    $additions = $item->additions()->inMenuOrder()->get();

    expect($additions)->toHaveCount(2);

    $extra = byEnglishName(MenuItemAddition::class, 'Extra paneer');
    $free = byEnglishName(MenuItemAddition::class, 'Less spicy');

    expect($extra->price_minor_units)->toBe(5000)
        ->and($extra->tenant_id)->toBe($restaurant->getKey())
        ->and($extra->menu_item_id)->toBe($item->getKey())
        ->and($extra->getTranslation('name', Locale::Tamil->value))->toBe('கூடுதல் பன்னீர்')
        // Zero is a real price: "no onions" costs nothing and is still listed.
        ->and($free->price_minor_units)->toBe(0)
        ->and($free->isFree())->toBeTrue();
});

it('lets a dish be saved with no additions at all', function (): void {
    $restaurant = Restaurant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // Most dishes have none, so the repeater must not start with a blank row
    // that then fails validation.
    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Tandoori Roti'],
            'menu_category_id' => $category->getKey(),
            'food_type' => FoodType::Vegetarian->value,
            'price' => '50',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    expect(byEnglishName(MenuItem::class, 'Tandoori Roti')->additions)->toBeEmpty();
});

it('takes a dish\'s additions with it when it is deleted', function (): void {
    $restaurant = Restaurant::factory()->create();
    $item = MenuItem::factory()->inCategory(
        MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))->create(),
    )->create();
    $addition = MenuItemAddition::factory()->onItem($item)->create();

    $item->delete();

    expect(MenuItemAddition::query()->whereKey($addition->getKey())->exists())->toBeFalse();
});

it('refuses at the database to hang an addition off another restaurant\'s dish', function (): void {
    $mine = Restaurant::factory()->create();
    $theirs = Restaurant::factory()->create();
    $theirItem = MenuItem::factory()->inCategory(
        MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $theirs->getKey()]))->create(),
    )->create();

    expect(fn () => DB::table('menu_item_additions')->insert([
        'tenant_id' => $mine->getKey(),
        'menu_item_id' => $theirItem->getKey(),
        'name' => json_encode([Locale::English->value => 'Smuggled'], JSON_THROW_ON_ERROR),
        'price_minor_units' => 1000,
        'is_available' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| One restaurant never reaches another's menu
|--------------------------------------------------------------------------
*/

it('shows only this restaurant\'s dishes', function (): void {
    $mine = Restaurant::factory()->create();
    $theirs = Restaurant::factory()->create();

    $myItem = MenuItem::factory()->inCategory(
        MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $mine->getKey()]))->create(),
    )->create();

    $theirItem = MenuItem::factory()->inCategory(
        MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $theirs->getKey()]))->create(),
    )->create();

    enterRestaurantPanel($mine, RoleEnum::Admin);

    Livewire::test(ListMenuItems::class)
        ->assertCanSeeTableRecords([$myItem])
        ->assertCanNotSeeTableRecords([$theirItem]);
});

it('shows only this restaurant\'s menus', function (): void {
    $mine = Restaurant::factory()->create();
    $theirs = Restaurant::factory()->create();

    $myMenu = Menu::factory()->create(['tenant_id' => $mine->getKey()]);
    $theirMenu = Menu::factory()->create(['tenant_id' => $theirs->getKey()]);

    enterRestaurantPanel($mine, RoleEnum::Admin);

    Livewire::test(ListMenus::class)
        ->assertCanSeeTableRecords([$myMenu])
        ->assertCanNotSeeTableRecords([$theirMenu]);
});

it('refuses to file a dish under another restaurant\'s section', function (): void {
    $mine = Restaurant::factory()->create();
    $theirs = Restaurant::factory()->create();

    MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $mine->getKey()]))->create();
    $theirCategory = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $theirs->getKey()]))
        ->create();

    enterRestaurantPanel($mine, RoleEnum::Admin);

    // Only this restaurant's sections are offered, and Filament validates the
    // submitted value against that list — so a tampered id is rejected here,
    // before the composite foreign key would have refused the row anyway.
    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Smuggled'],
            'menu_category_id' => $theirCategory->getKey(),
            'food_type' => FoodType::Vegetarian->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasActionErrors(['menu_category_id']);

    expect(MenuItem::query()->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, 'Smuggled')
        ->exists())->toBeFalse();
});

it('refuses at the database to file a dish under another restaurant\'s section', function (): void {
    $mine = Restaurant::factory()->create();
    $theirs = Restaurant::factory()->create();
    $theirCategory = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $theirs->getKey()]))
        ->create();

    // The composite foreign key is the guarantee behind the tenant scope: even
    // a tampered request cannot store a dish pointing across restaurants.
    expect(fn () => DB::table('menu_items')->insert([
        'tenant_id' => $mine->getKey(),
        'menu_category_id' => $theirCategory->getKey(),
        'name' => json_encode([Locale::English->value => 'Smuggled'], JSON_THROW_ON_ERROR),
        'price_minor_units' => 1000,
        'food_type' => FoodType::Vegetarian->value,
        'availability' => ItemAvailability::Available->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('takes a section\'s dishes with it when it is deleted', function (): void {
    $restaurant = Restaurant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();
    $item = MenuItem::factory()->inCategory($category)->create();

    $category->delete();

    expect(MenuItem::query()->whereKey($item->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| What a guest may actually order
|--------------------------------------------------------------------------
*/

it('counts an item orderable only when it, its section and its menu are showing', function (): void {
    $restaurant = Restaurant::factory()->create();

    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $hiddenMenu = Menu::factory()->hidden()->create(['tenant_id' => $restaurant->getKey()]);

    $showing = MenuCategory::factory()->inMenu($menu)->create();
    $hidden = MenuCategory::factory()->inMenu($menu)->hidden()->create();
    $onHiddenMenu = MenuCategory::factory()->inMenu($hiddenMenu)->create();

    $orderable = MenuItem::factory()->inCategory($showing)->create();
    $soldOut = MenuItem::factory()->inCategory($showing)->unavailable()->create();
    $inHiddenSection = MenuItem::factory()->inCategory($hidden)->create();
    $inHiddenMenu = MenuItem::factory()->inCategory($onHiddenMenu)->create();

    $names = MenuItem::query()->orderable()->get()->pluck('name')->all();

    expect($names)->toContain($orderable->name)
        ->and($names)->not->toContain($soldOut->name)
        ->and($names)->not->toContain($inHiddenSection->name)
        // Hiding a whole menu has to take everything under it down too.
        ->and($names)->not->toContain($inHiddenMenu->name);
});

/*
|--------------------------------------------------------------------------
| The dishes a menu leads with
|--------------------------------------------------------------------------
|
| Featuring is a flag on the dish, not a table of its own: a dish is either led
| with or it is not, and it keeps its place under its own section either way.
|
*/

it('features a dish from its own form', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $dish = MenuItem::factory()->inCategory($category)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // The dish form's Featured toggle is the only place featuring is set. The
    // menu page's featured row used to carry its own pair of actions for the
    // same flag; two mechanisms for one thing is what that was.
    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($dish), [
            'menu_category_id' => $category->getKey(),
            'name' => $dish->getTranslations('name'),
            'food_type' => $dish->food_type->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
            'is_featured' => true,
        ])
        ->assertHasNoActionErrors();

    expect($dish->refresh()->is_featured)->toBeTrue();

    Livewire::test(ManageMenuFeaturedItems::class, ['record' => $menu->getKey()])->assertCanSeeTableRecords([$dish]);
});

it('takes a dish out of the featured row without taking it off the menu', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $dish = MenuItem::factory()->inCategory($category)->create(['is_featured' => true]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($dish), [
            'menu_category_id' => $category->getKey(),
            'name' => $dish->getTranslations('name'),
            'food_type' => $dish->food_type->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
            'is_featured' => false,
        ])
        ->assertHasNoActionErrors();

    // Unfeaturing takes a dish out of the row it was led with, and nothing
    // else: it stays on the menu under its own section.
    expect($dish->refresh()->is_featured)->toBeFalse()
        ->and($dish->availability)->toBe(ItemAvailability::Available)
        ->and($dish->menu_category_id)->toBe($category->getKey());
});

it('offers no way to feature a dish from the menu page itself', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $dish = MenuItem::factory()->inCategory($category)->create(['is_featured' => true]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // The featured row is for putting dishes in order and nothing else, so the
    // flag has exactly one home.
    $table = Livewire::test(ManageMenuFeaturedItems::class, ['record' => $menu->getKey()])
        ->assertOk()
        ->instance()
        ->getTable();

    expect($table->getHeaderActions())->toBe([])
        ->and($table->getRecordActions())->toBe([])
        ->and($table->isReorderable())->toBeTrue();
});

it('shows only the featured dishes of this menu', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $otherMenu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $featured = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->create(['is_featured' => true]);
    $plain = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->create();
    $elsewhere = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($otherMenu)->create())
        ->create(['is_featured' => true]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ManageMenuFeaturedItems::class, ['record' => $menu->getKey()])
        ->assertCanSeeTableRecords([$featured])
        ->assertCanNotSeeTableRecords([$plain, $elsewhere]);
});

/*
|--------------------------------------------------------------------------
| Offers, availability and refiling
|--------------------------------------------------------------------------
|
| A dish carries a price, optionally a higher one struck through beside it, and
| a reason it is off the menu when it is.
|
*/

it('stores a struck-through price beside the one being charged', function (): void {
    $restaurant = Restaurant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Paneer Tikka'],
            'menu_category_id' => $category->getKey(),
            'food_type' => FoodType::Vegetarian->value,
            'price' => '299',
            'compare_at_price' => '360',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    $item = byEnglishName(MenuItem::class, 'Paneer Tikka');

    expect($item->price_minor_units)->toBe(29900)
        ->and($item->compare_at_price_minor_units)->toBe(36000)
        ->and($item->hasComparePrice())->toBeTrue()
        ->and($item->discountMinorUnits())->toBe(6100)
        ->and($item->formattedComparePrice())->toBe('₹360.00');
});

it('refuses a struck-through price that is not above what is charged', function (): void {
    $restaurant = Restaurant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Paneer Tikka'],
            'menu_category_id' => $category->getKey(),
            'food_type' => FoodType::Vegetarian->value,
            'price' => '299',
            'compare_at_price' => '250',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasActionErrors(['compare_at_price']);
});

it('leaves a dish that is not on offer with no compare-at price at all', function (): void {
    $item = MenuItem::factory()->create();

    // Null is "not on offer". A zero would be a price of nothing, and the
    // guest app would have to decide whether to believe it.
    expect($item->compare_at_price_minor_units)->toBeNull()
        ->and($item->hasComparePrice())->toBeFalse()
        ->and($item->formattedComparePrice())->toBeNull()
        ->and($item->discountMinorUnits())->toBe(0);
});

it('says why a dish is off the menu rather than only that it is', function (): void {
    $soldOut = MenuItem::factory()->unavailable()->create();
    $paused = MenuItem::factory()->unavailable(ItemAvailability::TemporarilyUnavailable)->create();

    expect($soldOut->availability)->toBe(ItemAvailability::OutOfStock)
        ->and($soldOut->isOrderable())->toBeFalse()
        ->and($paused->availability)->toBe(ItemAvailability::TemporarilyUnavailable)
        ->and($paused->isOrderable())->toBeFalse()
        // Both are off the menu for a guest; only the kitchen sees the reason.
        ->and(MenuItem::query()->orderable()->count())->toBe(0);
});

it('falls back to the restaurant GST rate on a dish, and overrides it when told', function (): void {
    $restaurant = Restaurant::factory()->create();
    $restaurant->settings->update(['tax_rate_basis_points' => 500]);
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();

    $food = MenuItem::factory()->inCategory($category)->create();
    // A sealed bottle sold alongside the food is taxed as goods, not service.
    $bottle = MenuItem::factory()->inCategory($category)->taxedAt(1800)->create();

    expect($food->taxRateBasisPoints())->toBe(500)
        ->and($food->overridesTaxRate())->toBeFalse()
        ->and($bottle->taxRateBasisPoints())->toBe(1800)
        ->and($bottle->overridesTaxRate())->toBeTrue();
});

it('accepts a rate no fixed list of GST slabs would have held', function (): void {
    $restaurant = Restaurant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // 40% is the demerit rate GST 2.0 introduced in September 2025, and 12.5%
    // is not a slab at all — the point of typing the rate rather than picking
    // it from a list is that neither has to be anticipated here.
    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Cola'],
            'menu_category_id' => $category->getKey(),
            'food_type' => FoodType::Vegetarian->value,
            'price' => '60',
            'availability' => ItemAvailability::Available->value,
            'tax_rate_percentage' => '40',
        ])
        ->assertHasNoActionErrors();

    expect(byEnglishName(MenuItem::class, 'Cola')->tax_rate_basis_points)->toBe(4000)
        ->and(PricingFields::toBasisPoints('12.5'))->toBe(1250);
});

it('taxes an addition at its own rate rather than the dish it sits on', function (): void {
    $restaurant = Restaurant::factory()->create();
    $restaurant->settings->update(['tax_rate_basis_points' => 500]);
    $dish = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu(
            Menu::factory()->create(['tenant_id' => $restaurant->getKey()])
        )->create())
        ->taxedAt(1200)
        ->create();

    $following = MenuItemAddition::factory()->onItem($dish)->create();
    $overriding = MenuItemAddition::factory()->onItem($dish)->taxedAt(1800)->create();

    // An addition that overrides is overriding because it differs from the
    // food, so inheriting the dish's 12% would be inheriting the wrong number.
    expect($following->taxRateBasisPoints())->toBe(500)
        ->and($overriding->taxRateBasisPoints())->toBe(1800);
});

it('refiles a dish into a sub-category from the dishes page', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $chicken = MenuCategory::factory()->under($category)->create();
    $dish = MenuItem::factory()->inCategory($category)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // Re-filing is an edit: the dish form's category select offers both levels
    // of every menu, so there is no second mechanism that has to repeat the
    // same rules.
    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($dish), [
            'menu_category_id' => $chicken->getKey(),
            'name' => $dish->getTranslations('name'),
            'food_type' => $dish->food_type->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    expect($dish->refresh()->menu_category_id)->toBe($chicken->getKey());
});

it('lifts a dish back out of a sub-category to the category itself', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $chicken = MenuCategory::factory()->under($category)->create();
    $dish = MenuItem::factory()->inCategory($chicken)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // Naming the section is how a dish comes back up a level; there is no
    // second field to clear.
    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($dish), [
            'menu_category_id' => $category->getKey(),
            'name' => $dish->getTranslations('name'),
            'food_type' => $dish->food_type->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    expect($dish->refresh()->menu_category_id)->toBe($category->getKey());
});

it('unfeatures a dish carried to another menu, and keeps one that stays', function (): void {
    $restaurant = Restaurant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $from = MenuCategory::factory()->inMenu($lunch)->create();
    $sibling = MenuCategory::factory()->inMenu($lunch)->create();
    $elsewhere = MenuCategory::factory()->inMenu($dinner)->create();

    $leaving = MenuItem::factory()->inCategory($from)->create(['is_featured' => true, 'featured_position' => 3]);
    $staying = MenuItem::factory()->inCategory($from)->create(['is_featured' => true, 'featured_position' => 4]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($leaving), [
            'menu_category_id' => $elsewhere->getKey(),
            'name' => $leaving->getTranslations('name'),
            'food_type' => $leaving->food_type->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
            'is_featured' => true,
        ])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('edit')->table($staying), [
            'menu_category_id' => $sibling->getKey(),
            'name' => $staying->getTranslations('name'),
            'food_type' => $staying->food_type->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
            'is_featured' => true,
        ])
        ->assertHasNoActionErrors();

    // The rule lives on the model, so it holds however the dish is written —
    // even when the form has just been told is_featured is true. A dish that
    // only moved within its own menu keeps its place in the row.
    expect($leaving->refresh()->is_featured)->toBeFalse()
        ->and($leaving->featured_position)->toBe(0)
        ->and($staying->refresh()->is_featured)->toBeTrue()
        ->and($staying->featured_position)->toBe(4);
});

it('refuses to refile a dish under a name the target category already has', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $from = MenuCategory::factory()->inMenu($menu)->create();
    $to = MenuCategory::factory()->inMenu($menu)->create();

    $moving = MenuItem::factory()->inCategory($from)->create(['name' => [Locale::English->value => 'Paneer Tikka']]);
    MenuItem::factory()->inCategory($to)->create(['name' => [Locale::English->value => 'Paneer Tikka']]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // The form's uniqueness rule is scoped to the category chosen in it, so
    // changing that select revalidates the name against where the dish is
    // going — caught here rather than at the expression index.
    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($moving), [
            'menu_category_id' => $to->getKey(),
            'name' => $moving->getTranslations('name'),
            'food_type' => $moving->food_type->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasActionErrors(['name.'.Locale::English->value]);

    expect($moving->refresh()->menu_category_id)->toBe($from->getKey());
});

it('rearranges the featured row by dragging it', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuItem::factory()->inCategory($category)->create(['is_featured' => true, 'featured_position' => 1, 'position' => 7]);
    $second = MenuItem::factory()->inCategory($category)->create(['is_featured' => true, 'featured_position' => 2, 'position' => 7]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // featured_position is its own order, separate from the position that
    // places a dish inside its section — a dish answers both at once.
    Livewire::test(ManageMenuFeaturedItems::class, ['record' => $menu->getKey()])->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($second->refresh()->featured_position)->toBeLessThan($first->refresh()->featured_position)
        // Dragging the featured row must not disturb where either dish sits
        // in its own section.
        ->and($first->position)->toBe($second->position);
});

it('keeps rearranging the featured row away from someone who may only read the menu', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuItem::factory()->inCategory($category)->create(['is_featured' => true, 'featured_position' => 1]);
    $second = MenuItem::factory()->inCategory($category)->create(['is_featured' => true, 'featured_position' => 2]);

    enterRestaurantPanel($restaurant, RoleEnum::Staff);

    Livewire::test(ManageMenuFeaturedItems::class, ['record' => $menu->getKey()])->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($first->refresh()->featured_position)->toBe(1)
        ->and($second->refresh()->featured_position)->toBe(2);
});
