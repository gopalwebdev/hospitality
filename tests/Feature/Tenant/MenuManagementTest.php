<?php

use App\Actions\Menus\QuoteBasket;
use App\Enums\Currency;
use App\Enums\Diet;
use App\Enums\ItemAvailability;
use App\Enums\Locale;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Schemas\PricingFields;
use App\Filament\Tenant\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Tenant\Resources\Menus\Pages\ArrangeMenu;
use App\Filament\Tenant\Resources\Menus\Pages\ListMenus;
use App\Filament\Tenant\Resources\Menus\RelationManagers\FeaturedItemsRelationManager;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Find a record by the English half of its translated name.
 *
 * Every uniqueness rule is checked on this value, and so is every
 * lookup here — matching the whole JSON document would only find a record whose
 * every language happened to agree.
 *
 * @param  class-string<Menu|MenuCategory|MenuItem>  $model
 */
function byEnglishName(string $model, string $name): Menu|MenuCategory|MenuItem
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
| anything, and only a tenant owner has it.
|
*/

it('lets a tenant owner manage the menu', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $item = MenuItem::factory()->inCategory(MenuCategory::factory()->inMenu($menu)->create())->create();

    $owner = enterTenantPanel($tenant, RoleEnum::Owner);

    expect($owner->can('viewAny', MenuItem::class))->toBeTrue()
        ->and($owner->can('create', MenuItem::class))->toBeTrue()
        ->and($owner->can('update', $item))->toBeTrue()
        ->and($owner->can('delete', $item))->toBeTrue()
        ->and($owner->can('viewAny', Menu::class))->toBeTrue()
        ->and($owner->can('create', Menu::class))->toBeTrue()
        ->and($owner->can('update', $menu))->toBeTrue();
});

it('lets staff read the menu but not change it', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $item = MenuItem::factory()->inCategory(MenuCategory::factory()->inMenu($menu)->create())->create();

    $staff = enterTenantPanel($tenant, RoleEnum::Staff);

    expect($staff->can(PermissionEnum::MenuView->value))->toBeTrue()
        ->and($staff->can('viewAny', MenuItem::class))->toBeTrue()
        ->and($staff->can('viewAny', Menu::class))->toBeTrue()
        ->and($staff->can('create', MenuItem::class))->toBeFalse()
        ->and($staff->can('create', Menu::class))->toBeFalse()
        ->and($staff->can('update', $item))->toBeFalse()
        ->and($staff->can('delete', $item))->toBeFalse();
});

it('keeps someone with no role off the menu pages', function (): void {
    $tenant = Tenant::factory()->create();
    $nobody = User::factory()->ofTenant($tenant)->create();

    expect($nobody->can('viewAny', MenuItem::class))->toBeFalse()
        ->and($nobody->can('viewAny', MenuCategory::class))->toBeFalse()
        ->and($nobody->can('viewAny', Menu::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Menus
|--------------------------------------------------------------------------
*/

it('creates a menu against the tenant whose panel it is', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenus::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Dinner', Locale::Tamil->value => 'இரவு உணவு'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $menu = byEnglishName(Menu::class, 'Dinner');

    expect($menu->tenant_id)->toBe($tenant->getKey())
        ->and($menu->is_active)->toBeTrue()
        ->and($menu->getTranslations('name'))->toBe([
            Locale::English->value => 'Dinner',
            Locale::Tamil->value => 'இரவு உணவு',
        ]);
});

it('lets a menu be created in English alone', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Owner);

    // A tenant that has not translated its menu yet is the normal state on
    // day one, so only the fallback language is required.
    Livewire::test(ListMenus::class)
        ->callAction('create', ['name' => [Locale::English->value => 'Lunch'], 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(byEnglishName(Menu::class, 'Lunch')->name)->toBe('Lunch');
});

it('requires the fallback language on a menu', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Owner);

    // Tamil alone would leave an English-reading guest with a blank heading,
    // and there would be no English name for uniqueness to check.
    Livewire::test(ListMenus::class)
        ->callAction('create', ['name' => [Locale::Tamil->value => 'இரவு உணவு'], 'is_active' => true])
        ->assertHasActionErrors(['name.'.Locale::English->value]);
});

it('refuses a menu name the tenant already uses', function (): void {
    $tenant = Tenant::factory()->create();
    Menu::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => [Locale::English->value => 'Dinner'],
    ]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenus::class)
        ->callAction('create', ['name' => [Locale::English->value => 'Dinner'], 'is_active' => true])
        ->assertHasActionErrors(['name.'.Locale::English->value]);
});

it('lists menus by name rather than in an order kept by hand', function (): void {
    $tenant = Tenant::factory()->create();
    $dinner = Menu::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => [Locale::English->value => 'Dinner']]);
    $breakfast = Menu::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => [Locale::English->value => 'Breakfast']]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // A guest reaches a menu through a home screen tile, so nothing reads the
    // order of this list; it only has to be easy to scan.
    $page = Livewire::test(ListMenus::class)
        ->assertCanSeeTableRecords([$breakfast, $dinner], inOrder: true);

    expect($page->instance()->getTable()->isReorderable())->toBeFalse();
});

it('takes a menu\'s sections and their items with it when it is deleted, and leaves its add-on groups', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $item = MenuItem::factory()->inCategory($category)->create();
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    $link = MenuItemAddOnGroup::factory()->linking($item, $group)->create();

    $menu->delete();

    expect(MenuCategory::query()->whereKey($category->getKey())->exists())->toBeFalse()
        ->and(MenuItem::query()->whereKey($item->getKey())->exists())->toBeFalse()
        ->and(MenuItemAddOnGroup::query()->whereKey($link->getKey())->exists())->toBeFalse()
        // A group is the tenant's, and may be offered on another menu's items.
        ->and(MenuAddOnGroup::query()->whereKey($group->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Sections
|--------------------------------------------------------------------------
*/

it('creates a section on the menu it was filed under', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::English->value => 'Starters', Locale::Tamil->value => 'தொடக்கங்கள்'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $category = byEnglishName(MenuCategory::class, 'Starters');

    expect($category->tenant_id)->toBe($tenant->getKey())
        ->and($category->menu_id)->toBe($menu->getKey())
        ->and($category->is_active)->toBeTrue()
        ->and($category->getTranslation('name', Locale::Tamil->value))->toBe('தொடக்கங்கள்');
});

it('refuses a section name the same menu already uses', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Starters']]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::English->value => 'Starters'],
            'is_active' => true,
        ])
        ->assertHasActionErrors(['name.'.Locale::English->value]);
});

it('lets a lunch and a dinner menu each have their own Starters', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    MenuCategory::factory()->inMenu($lunch)->create(['name' => [Locale::English->value => 'Starters']]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Uniqueness moved from the tenant to the menu when menus arrived, and
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

it('lets two tenants both have a section of the same name', function (): void {
    $other = Tenant::factory()->create();
    MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $other->getKey()]))
        ->create(['name' => [Locale::English->value => 'Starters']]);

    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::English->value => 'Starters'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    // The panel is booted, so MenuCategory carries a tenancy scope. Counting
    // across tenants has to step outside it deliberately.
    expect(MenuCategory::query()->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, 'Starters')
        ->count())->toBe(2);
});

it('refuses a section on another tenant\'s menu, even around the form', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();
    $theirMenu = Menu::factory()->create(['tenant_id' => $theirs->getKey()]);

    expect(fn () => MenuCategory::factory()->create([
        'tenant_id' => $mine->getKey(),
        'menu_id' => $theirMenu->getKey(),
    ]))->toThrow(LogicException::class, 'another tenant');
});

it('reads a menu as one tree without grouping or ordering on a translated column', function (): void {
    // Grouping is the trap this replaced. menu_categories.name is a translated
    // column, and a Filament group orders by the attribute it groups on — which
    // 500'd while the column was plain json, which has no ordering operator.
    //
    // The arrangement has no grouping at all: it is one list built in reading
    // order, so the nesting survives drag mode, which a grouped table's never
    // did (Filament turns grouping off while reordering).
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $starters = MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $chicken = MenuCategory::factory()->under($starters)->create(['position' => 0]);
    $menuItem = MenuItem::factory()->inCategory($starters)->create(['position' => 0]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    $table = Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->assertOk()
        ->instance()
        ->getTable();

    expect($table->getDefaultGroup())->toBeNull()
        // A section, then its subdivisions: the order a guest reads the menu
        // in. Nothing is featured and there are no combos, so neither of those
        // rows is drawn, and the section's item opens in a table of its own.
        ->and(array_keys($table->getRecords()->all()))->toBe([
            'category-'.$starters->getKey(),
            'category-'.$chicken->getKey(),
        ])
        ->and($menuItem->menu_category_id)->toBe($starters->getKey());
});

/*
|--------------------------------------------------------------------------
| Items, and the money they are priced in
|--------------------------------------------------------------------------
*/

it('stores a typed price as an exact integer count of minor units', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings->update(['currency' => Currency::IndianRupee]);
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Paneer Tikka'],
            'menu_category_id' => $category->getKey(),
            'diet' => Diet::Vegetarian->value,
            'price' => '249.50',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    $item = byEnglishName(MenuItem::class, 'Paneer Tikka');

    // ₹249.50 is 24950 paise, exactly. No float ever reaches the column.
    expect($item->price_minor_units)->toBe(24950)
        ->and($item->price_minor_units)->toBeInt()
        ->and($item->formattedPrice())->toBe('₹249.50')
        ->and($item->tenant_id)->toBe($tenant->getKey());
});

it('round-trips a price through the edit form without drift', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();
    $item = MenuItem::factory()->inCategory($category)->create(['price_minor_units' => 24950]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($item), [
            'name' => $item->getTranslations('name'),
            'menu_category_id' => $category->getKey(),
            'diet' => $item->diet->value,
            'price' => '249.50',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    expect($item->refresh()->price_minor_units)->toBe(24950);
});

it('fills the edit form with every language, not just the current one', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();
    $item = MenuItem::factory()->inCategory($category)->create([
        'name' => [Locale::English->value => 'Paneer Tikka', Locale::Tamil->value => 'பன்னீர் டிக்கா'],
    ]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Spatie hands back one language for a translated attribute; the form edits
    // them all, so the whole document has to be put back before filling.
    Livewire::test(ListMenuItems::class)
        ->mountAction(TestAction::make('edit')->table($item))
        ->assertActionDataSet([
            'name' => [Locale::English->value => 'Paneer Tikka', Locale::Tamil->value => 'பன்னீர் டிக்கா'],
        ]);
});

it('formats a price in rupees', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();
    $item = MenuItem::factory()->inCategory($category)->create(['price_minor_units' => 1250]);

    expect($item->formattedPrice())->toBe('₹12.50');
});

it('offers no grouping control on the items page', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    MenuItem::factory()->inCategory(MenuCategory::factory()->inMenu($menu)->create())->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

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
| Add-on groups on an item
|--------------------------------------------------------------------------
|
| An item names groups from the tenant's library. The rows it keeps are only
| the links and their order; the groups and their options are edited on the
| Add-on groups page.
|
*/

it('offers an item the add-on groups picked for it, in the order they were put in', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings->update(['currency' => Currency::IndianRupee]);
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();
    $extras = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    $bread = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(1, 1)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Paneer Butter Masala'],
            'menu_category_id' => $category->getKey(),
            'diet' => Diet::Vegetarian->value,
            'price' => '289',
            'availability' => ItemAvailability::Available->value,
            'addOnGroupLinks' => [
                ['menu_add_on_group_id' => $bread->getKey()],
                ['menu_add_on_group_id' => $extras->getKey()],
            ],
        ])
        ->assertHasNoActionErrors();

    $item = byEnglishName(MenuItem::class, 'Paneer Butter Masala');

    expect($item->addOnGroupLinks()->inMenuOrder()->pluck('menu_add_on_group_id')->all())
        ->toBe([$bread->getKey(), $extras->getKey()])
        ->and($item->addOnGroupLinks()->pluck('tenant_id')->unique()->all())->toBe([$tenant->getKey()]);
});

it('refuses the same group twice on one item, and a group this tenant does not have', function (array $pick): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();
    $spice = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    $theirs = MenuAddOnGroup::factory()->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Gobi Manchurian'],
            'menu_category_id' => $category->getKey(),
            'diet' => Diet::Vegetarian->value,
            'price' => '210',
            'availability' => ItemAvailability::Available->value,
            'addOnGroupLinks' => array_map(
                fn (string $group): array => ['menu_add_on_group_id' => ($group === 'mine' ? $spice : $theirs)->getKey()],
                $pick,
            ),
        ])
        ->assertHasActionErrors();

    expect(MenuItem::query()->withoutGlobalScopes()->exists())->toBeFalse();
})->with([
    'the same group twice' => [['mine', 'mine']],
    "another tenant's group" => [['theirs']],
]);

it('lets an item be saved with no add-on groups at all', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Most items have none, so the repeater must not start with a blank row
    // that then fails validation.
    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Tandoori Roti'],
            'menu_category_id' => $category->getKey(),
            'diet' => Diet::Vegetarian->value,
            'price' => '50',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    expect(byEnglishName(MenuItem::class, 'Tandoori Roti')->addOnGroupLinks()->exists())->toBeFalse();
});

it('takes an item\'s links with it when it is deleted, and leaves the groups for other items', function (): void {
    $tenant = Tenant::factory()->create();
    $item = MenuItem::factory()->inCategory(
        MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))->create(),
    )->create();
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    $link = MenuItemAddOnGroup::factory()->linking($item, $group)->create();

    $item->delete();

    expect(MenuItemAddOnGroup::query()->whereKey($link->getKey())->exists())->toBeFalse()
        ->and(MenuAddOnGroup::query()->whereKey($group->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| One tenant never reaches another's menu
|--------------------------------------------------------------------------
*/

it('shows only this tenant\'s items', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    $myItem = MenuItem::factory()->inCategory(
        MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $mine->getKey()]))->create(),
    )->create();

    $theirItem = MenuItem::factory()->inCategory(
        MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $theirs->getKey()]))->create(),
    )->create();

    enterTenantPanel($mine, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->assertCanSeeTableRecords([$myItem])
        ->assertCanNotSeeTableRecords([$theirItem]);
});

it('shows only this tenant\'s menus', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    $myMenu = Menu::factory()->create(['tenant_id' => $mine->getKey()]);
    $theirMenu = Menu::factory()->create(['tenant_id' => $theirs->getKey()]);

    enterTenantPanel($mine, RoleEnum::Owner);

    Livewire::test(ListMenus::class)
        ->assertCanSeeTableRecords([$myMenu])
        ->assertCanNotSeeTableRecords([$theirMenu]);
});

it('refuses to file an item under another tenant\'s section', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $mine->getKey()]))->create();
    $theirCategory = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $theirs->getKey()]))
        ->create();

    enterTenantPanel($mine, RoleEnum::Owner);

    // Only this tenant's sections are offered, and Filament validates the
    // submitted value against that list — so a tampered id is rejected here,
    // before MenuItemObserver would have refused the row anyway.
    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Smuggled'],
            'menu_category_id' => $theirCategory->getKey(),
            'diet' => Diet::Vegetarian->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasActionErrors(['menu_category_id']);

    expect(MenuItem::query()->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, 'Smuggled')
        ->exists())->toBeFalse();
});

it('refuses an item in another tenant\'s section, even around the form', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();
    $theirCategory = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $theirs->getKey()]))
        ->create();

    // MenuItemObserver is the guarantee behind the tenant scope: code that goes
    // around the form still cannot store an item pointing across tenants.
    expect(fn () => MenuItem::factory()->create([
        'tenant_id' => $mine->getKey(),
        'menu_category_id' => $theirCategory->getKey(),
    ]))->toThrow(LogicException::class, 'another tenant');
});

it('takes a section\'s items with it when it is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
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
    $tenant = Tenant::factory()->create();

    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $hiddenMenu = Menu::factory()->hidden()->create(['tenant_id' => $tenant->getKey()]);

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
| The items a menu leads with
|--------------------------------------------------------------------------
|
| Featuring is a flag on the item, not a table of its own: an item is either led
| with or it is not, and it keeps its place under its own section either way.
|
*/

it('features an item from its own form', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $menuItem = MenuItem::factory()->inCategory($category)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The item form's Featured toggle is one way to feature an item; Feature
    // items on the menu page is the other, and both write this one flag.
    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($menuItem), [
            'menu_category_id' => $category->getKey(),
            'name' => $menuItem->getTranslations('name'),
            'diet' => $menuItem->diet->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
            'is_featured' => true,
        ])
        ->assertHasNoActionErrors();

    expect($menuItem->refresh()->is_featured)->toBeTrue();

    Livewire::test(FeaturedItemsRelationManager::class, ['ownerRecord' => $menu, 'pageClass' => ArrangeMenu::class])
        ->assertCanSeeTableRecords([$menuItem]);
});

it('takes an item out of the featured row without taking it off the menu', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $menuItem = MenuItem::factory()->inCategory($category)->create(['is_featured' => true]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($menuItem), [
            'menu_category_id' => $category->getKey(),
            'name' => $menuItem->getTranslations('name'),
            'diet' => $menuItem->diet->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
            'is_featured' => false,
        ])
        ->assertHasNoActionErrors();

    // Unfeaturing takes an item out of the row it was led with, and nothing
    // else: it stays on the menu under its own section.
    expect($menuItem->refresh()->is_featured)->toBeFalse()
        ->and($menuItem->availability)->toBe(ItemAvailability::Available)
        ->and($menuItem->menu_category_id)->toBe($category->getKey());
});

it('offers only this menu\'s unfeatured items to feature from the menu page', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $otherMenu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $category = MenuCategory::factory()->inMenu($menu)->create();
    $featured = MenuItem::factory()->inCategory($category)->create(['is_featured' => true]);
    $plain = MenuItem::factory()->inCategory($category)->create();
    MenuItem::factory()->inCategory(MenuCategory::factory()->inMenu($otherMenu)->create())->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Featuring belongs to one menu, so another menu's items are not on offer,
    // and an item already led with is not offered a second time.
    Livewire::test(FeaturedItemsRelationManager::class, ['ownerRecord' => $menu, 'pageClass' => ArrangeMenu::class])
        ->mountAction(TestAction::make('featureItems')->table())
        ->assertSchemaComponentExists('items', checkComponentUsing: function ($component) use ($plain): bool {
            expect(array_keys($component->getOptions()))->toBe([$plain->getKey()]);

            return true;
        });

    expect($featured->refresh()->is_featured)->toBeTrue();
});

it('shows only the featured items of this menu', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $otherMenu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $featured = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->create(['is_featured' => true]);
    $plain = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->create();
    $elsewhere = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($otherMenu)->create())
        ->create(['is_featured' => true]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(FeaturedItemsRelationManager::class, ['ownerRecord' => $menu, 'pageClass' => ArrangeMenu::class])
        ->assertCanSeeTableRecords([$featured])
        ->assertCanNotSeeTableRecords([$plain, $elsewhere]);
});

/*
|--------------------------------------------------------------------------
| Offers, availability and refiling
|--------------------------------------------------------------------------
|
| An item carries a price, optionally a higher one struck through beside it, and
| a reason it is off the menu when it is.
|
*/

it('stores a struck-through price beside the one being charged', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Paneer Tikka'],
            'menu_category_id' => $category->getKey(),
            'diet' => Diet::Vegetarian->value,
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
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Paneer Tikka'],
            'menu_category_id' => $category->getKey(),
            'diet' => Diet::Vegetarian->value,
            'price' => '299',
            'compare_at_price' => '250',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasActionErrors(['compare_at_price']);
});

it('leaves an item that is not on offer with no compare-at price at all', function (): void {
    $item = MenuItem::factory()->create();

    // Null is "not on offer". A zero would be a price of nothing, and the
    // guest app would have to decide whether to believe it.
    expect($item->compare_at_price_minor_units)->toBeNull()
        ->and($item->hasComparePrice())->toBeFalse()
        ->and($item->formattedComparePrice())->toBeNull()
        ->and($item->discountMinorUnits())->toBe(0);
});

it('says why an item is off the menu rather than only that it is', function (): void {
    $soldOut = MenuItem::factory()->unavailable()->create();
    $paused = MenuItem::factory()->unavailable(ItemAvailability::TemporarilyUnavailable)->create();

    expect($soldOut->availability)->toBe(ItemAvailability::OutOfStock)
        ->and($soldOut->isOrderable())->toBeFalse()
        ->and($paused->availability)->toBe(ItemAvailability::TemporarilyUnavailable)
        ->and($paused->isOrderable())->toBeFalse()
        // Both are off the menu for a guest; only the kitchen sees the reason.
        ->and(MenuItem::query()->orderable()->count())->toBe(0);
});

it('falls back to the tenant GST rate on an item, and overrides it when told', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings->update(['tax_rate_basis_points' => 500]);
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();

    $standard = MenuItem::factory()->inCategory($category)->create();
    // A sealed bottle sold alongside the rest of the menu is taxed as goods, not service.
    $bottle = MenuItem::factory()->inCategory($category)->taxedAt(1800)->create();

    expect($standard->taxRateBasisPoints())->toBe(500)
        ->and($standard->overridesTaxRate())->toBeFalse()
        ->and($bottle->taxRateBasisPoints())->toBe(1800)
        ->and($bottle->overridesTaxRate())->toBeTrue();
});

it('accepts a rate no fixed list of GST slabs would have held', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // 40% is the demerit rate GST 2.0 introduced in September 2025, and 12.5%
    // is not a slab at all — the point of typing the rate rather than picking
    // it from a list is that neither has to be anticipated here.
    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Cola'],
            'menu_category_id' => $category->getKey(),
            'diet' => Diet::Vegetarian->value,
            'price' => '60',
            'availability' => ItemAvailability::Available->value,
            'tax_rate_percentage' => '40',
        ])
        ->assertHasNoActionErrors();

    expect(byEnglishName(MenuItem::class, 'Cola')->tax_rate_basis_points)->toBe(4000)
        ->and(PricingFields::toBasisPoints('12.5'))->toBe(1250);
});

it('taxes an option at the rate of the item it is added to', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings->update(['tax_rate_basis_points' => 500, 'prices_include_tax' => false]);
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $menuItem = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu($menu)->create())
        ->taxedAt(1200)
        ->create(['price_minor_units' => 10000]);
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    MenuItemAddOnGroup::factory()->linking($menuItem, $group)->create();
    $cheese = MenuAddOnOption::factory()->inGroup($group)->create(['price_minor_units' => 5000]);

    // An add-on is part of the item it is added to — a composite supply, taxed
    // at the rate of its principal supply (CGST Act, s. 8(a)) — so the cheese
    // pays the item's 12%, not the tenant's 5%.
    $quote = app(QuoteBasket::class)($tenant, $menu, [[
        'key' => 'tikka',
        'type' => QuoteBasket::ITEM,
        'id' => $menuItem->getKey(),
        'quantity' => 1,
        'choices' => [['optionId' => $cheese->getKey(), 'quantity' => 1]],
    ]]);

    expect($quote['taxMinorUnits'])->toBe(1200 + 600);
});

/*
|--------------------------------------------------------------------------
| Service requests
|--------------------------------------------------------------------------
|
| An extra pillow sits on the same menu as a bottle of water. Everything that
| is not a service request carries a diet mark; a service request never does.
|
*/

it('saves a service request without a diet mark', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Extra Pillow'],
            'menu_category_id' => $category->getKey(),
            'is_service_request' => true,
            'price' => '0',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    $pillow = byEnglishName(MenuItem::class, 'Extra Pillow');

    // A pillow has no diet to declare, and costs a guest nothing.
    expect($pillow->is_service_request)->toBeTrue()
        ->and($pillow->diet)->toBeNull()
        ->and($pillow->isComplimentary())->toBeTrue();
});

it('asks for a diet mark on anything that is not a service request', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Water Bottle'],
            'menu_category_id' => $category->getKey(),
            'is_service_request' => false,
            'diet' => null,
            'price' => '40',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasActionErrors(['diet' => 'required']);

    expect(MenuItem::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('drops the diet mark of an item that becomes a service request', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()
        ->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();
    $menuItem = MenuItem::factory()->inCategory($category)->create(['diet' => Diet::Vegetarian]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($menuItem), [
            'menu_category_id' => $category->getKey(),
            'name' => $menuItem->getTranslations('name'),
            'is_service_request' => true,
            'price' => '0',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    expect($menuItem->refresh()->is_service_request)->toBeTrue()
        ->and($menuItem->diet)->toBeNull();
});

it('refuses an item with no diet mark that is not a service request, even around the form', function (): void {
    $category = MenuCategory::factory()->create();

    // MenuItemObserver is the backstop the CHECK constraint mirrors: code that
    // writes around the form still cannot store an item that is neither.
    expect(fn () => MenuItem::factory()->inCategory($category)->create(['diet' => null]))
        ->toThrow(LogicException::class);
});

it('refiles an item into a sub-category from the items page', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $chicken = MenuCategory::factory()->under($category)->create();
    $menuItem = MenuItem::factory()->inCategory($category)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Re-filing is an edit: the item form's category select offers both levels
    // of every menu, so there is no second mechanism that has to repeat the
    // same rules.
    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($menuItem), [
            'menu_category_id' => $chicken->getKey(),
            'name' => $menuItem->getTranslations('name'),
            'diet' => $menuItem->diet->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    expect($menuItem->refresh()->menu_category_id)->toBe($chicken->getKey());
});

it('lifts an item back out of a sub-category to the category itself', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $chicken = MenuCategory::factory()->under($category)->create();
    $menuItem = MenuItem::factory()->inCategory($chicken)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Naming the section is how an item comes back up a level; there is no
    // second field to clear.
    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($menuItem), [
            'menu_category_id' => $category->getKey(),
            'name' => $menuItem->getTranslations('name'),
            'diet' => $menuItem->diet->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasNoActionErrors();

    expect($menuItem->refresh()->menu_category_id)->toBe($category->getKey());
});

it('unfeatures an item carried to another menu, and keeps one that stays', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $from = MenuCategory::factory()->inMenu($lunch)->create();
    $sibling = MenuCategory::factory()->inMenu($lunch)->create();
    $elsewhere = MenuCategory::factory()->inMenu($dinner)->create();

    $leaving = MenuItem::factory()->inCategory($from)->create(['is_featured' => true, 'featured_position' => 3]);
    $staying = MenuItem::factory()->inCategory($from)->create(['is_featured' => true, 'featured_position' => 4]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($leaving), [
            'menu_category_id' => $elsewhere->getKey(),
            'name' => $leaving->getTranslations('name'),
            'diet' => $leaving->diet->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
            'is_featured' => true,
        ])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('edit')->table($staying), [
            'menu_category_id' => $sibling->getKey(),
            'name' => $staying->getTranslations('name'),
            'diet' => $staying->diet->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
            'is_featured' => true,
        ])
        ->assertHasNoActionErrors();

    // The rule lives on the model, so it holds however the item is written —
    // even when the form has just been told is_featured is true. An item that
    // only moved within its own menu keeps its place in the row.
    expect($leaving->refresh()->is_featured)->toBeFalse()
        ->and($leaving->featured_position)->toBe(0)
        ->and($staying->refresh()->is_featured)->toBeTrue()
        ->and($staying->featured_position)->toBe(4);
});

it('refuses to refile an item under a name the target category already has', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $from = MenuCategory::factory()->inMenu($menu)->create();
    $to = MenuCategory::factory()->inMenu($menu)->create();

    $moving = MenuItem::factory()->inCategory($from)->create(['name' => [Locale::English->value => 'Paneer Tikka']]);
    MenuItem::factory()->inCategory($to)->create(['name' => [Locale::English->value => 'Paneer Tikka']]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The form's uniqueness rule is scoped to the category chosen in it, so
    // changing that select revalidates the name against where the item is
    // going — the only place a duplicate is caught.
    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($moving), [
            'menu_category_id' => $to->getKey(),
            'name' => $moving->getTranslations('name'),
            'diet' => $moving->diet->value,
            'price' => '100',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasActionErrors(['name.'.Locale::English->value]);

    expect($moving->refresh()->menu_category_id)->toBe($from->getKey());
});

it('rearranges the featured row by dragging it', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuItem::factory()->inCategory($category)->create(['is_featured' => true, 'featured_position' => 1, 'position' => 1]);
    $second = MenuItem::factory()->inCategory($category)->create(['is_featured' => true, 'featured_position' => 2, 'position' => 2]);

    // Featured on another of this tenant's menus. Its id arriving in the drag
    // must not move it: the featured items table is one menu's.
    $elsewhere = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))->create())
        ->create(['is_featured' => true, 'featured_position' => 7]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // featured_position is its own order, separate from the position that
    // places an item inside its category — an item answers both at once, and
    // is a row in its category's table and in the featured items table. That
    // table hangs off a HasManyThrough, whose join Filament's own reorder
    // cannot update, so this calls its override for real.
    Livewire::test(FeaturedItemsRelationManager::class, ['ownerRecord' => $menu, 'pageClass' => ArrangeMenu::class])
        ->call('reorderTable', [$second->getKey(), $elsewhere->getKey(), $first->getKey()]);

    expect($second->refresh()->featured_position)->toBeLessThan($first->refresh()->featured_position)
        // Dragging the featured items must not disturb where either item sits
        // in its own category.
        ->and($first->refresh()->position)->toBeLessThan($second->refresh()->position)
        ->and($elsewhere->refresh()->featured_position)->toBe(7);
});

it('keeps rearranging the featured row away from someone who may only read the menu', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuItem::factory()->inCategory($category)->create(['is_featured' => true, 'featured_position' => 1]);
    $second = MenuItem::factory()->inCategory($category)->create(['is_featured' => true, 'featured_position' => 2]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(FeaturedItemsRelationManager::class, ['ownerRecord' => $menu, 'pageClass' => ArrangeMenu::class])
        ->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($first->refresh()->featured_position)->toBe(1)
        ->and($second->refresh()->featured_position)->toBe(2);
});
