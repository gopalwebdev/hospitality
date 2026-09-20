<?php

use App\Actions\Menus\MoveCategoryToMenu;
use App\Enums\Diet;
use App\Enums\ItemAvailability;
use App\Enums\Locale;
use App\Enums\MenuRailType;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Tenant\Resources\Menus\MenuResource;
use App\Filament\Tenant\Resources\Menus\Pages\ArrangeMenu;
use App\Filament\Tenant\Resources\Menus\RelationManagers\CategoryItemsRelationManager;
use App\Filament\Tenant\Resources\Menus\RelationManagers\FeaturedItemsRelationManager;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuComboItem;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use App\Models\MenuRail;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Find a category, at either level, by the English half of its name.
 */
function categoryNamed(string $name): MenuCategory
{
    return MenuCategory::query()
        ->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, $name)
        ->sole();
}

/**
 * Open the menu page: the outline a menu is arranged on.
 *
 * Its rails, categories and sub-categories are the rows of this one table — see MenuArrangementTable.
 */
function arrangementOf(Menu $menu): Testable
{
    return Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()]);
}

/**
 * Open the table a category's row opens, where its items are ordered, added, edited and deleted.
 */
function itemsOf(MenuCategory $category): Testable
{
    return Livewire::test(CategoryItemsRelationManager::class, ['ownerRecord' => $category, 'pageClass' => ArrangeMenu::class]);
}

/**
 * Open the table the Featured items row opens.
 */
function featuredOf(Menu $menu): Testable
{
    return Livewire::test(FeaturedItemsRelationManager::class, ['ownerRecord' => $menu, 'pageClass' => ArrangeMenu::class]);
}

/**
 * The key the arrangement table gives a category's row.
 */
function categoryRow(MenuCategory $category): string
{
    return 'category-'.$category->getKey();
}

/*
|--------------------------------------------------------------------------
| Two levels, one table
|--------------------------------------------------------------------------
|
| A category with no parent is a section of the menu; one with a parent is a
| subdivision of that section. An item names exactly one of them.
|
*/

it('subdivides a category from the menu page', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    arrangementOf($menu)
        ->callAction(TestAction::make('createSubCategory')->table(categoryRow($category)), [
            'parent_id' => $category->getKey(),
            'name' => [Locale::English->value => 'Chicken', Locale::Tamil->value => 'சிக்கன்'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $subCategory = categoryNamed('Chicken');

    // The menu and the tenant are both derived rather than typed: the
    // relation sets menu_id, and MenuCategoryObserver takes the tenant.
    expect($subCategory->parent_id)->toBe($category->getKey())
        ->and($subCategory->menu_id)->toBe($menu->getKey())
        ->and($subCategory->tenant_id)->toBe($tenant->getKey())
        ->and($subCategory->isSubCategory())->toBeTrue()
        ->and($subCategory->getTranslation('name', Locale::Tamil->value))->toBe('சிக்கன்');
});

it('opens its modals the way the browser asks for them', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // callAction() builds its own context, so it cannot catch this: the *page*
    // has a record — the menu — and Filament hands it to any action that has
    // not been given one. Action::getContext() only filters that out by
    // comparing it against the table's model, which custom data has none of, so
    // the menu's id shipped as the row key. Mounting then looked for a row
    // keyed `1` among rows keyed `category-3`, found none, and quietly declined:
    // every button on this page did nothing at all, and nothing was logged.
    $html = (string) $this->get(MenuResource::getUrl('arrange', ['record' => $menu, 'tenant' => $tenant]))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('mountAction(\'createCategory\', {}, '.Js::from(['table' => true]).')')
        ->toContain('mountAction(\'open\', {}, '.Js::from(['recordKey' => categoryRow($category), 'table' => true]).')');

    // And the mount those handlers make really does open the modal.
    $page = Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->call('mountAction', 'createCategory', [], ['table' => true]);

    expect($page->get('mountedActions'))->toHaveCount(1);

    $page->call('mountAction', 'rename', [], ['recordKey' => categoryRow($category), 'table' => true]);

    expect($page->get('mountedActions'))->toHaveCount(1);
});

it('redraws the arrangement from what was saved once an action has run', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $renamed = MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Starters']]);
    $deleted = MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Doomed']]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Filament reads every row to find the one a row action is about and keeps
    // that copy for the request, so the page used to be redrawn from rows taken
    // *before* the write: the old name stayed and a deleted category stayed.
    $page = arrangementOf($menu)
        ->callAction(TestAction::make('rename')->table(categoryRow($renamed)), [
            'name' => [Locale::English->value => 'Appetisers'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    expect($page->html())->toContain('Appetisers')->not->toContain('Starters');

    $page->callAction(TestAction::make('delete')->table(categoryRow($deleted)));

    expect($page->html())->not->toContain('Doomed');
});

it('adds a category to the end of the menu rather than the top', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $last = MenuCategory::factory()->inMenu($menu)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    arrangementOf($menu)
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::English->value => 'Desserts'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    // Where a tenant adding a section looks for it. Positions are never
    // typed — see .ai/rules/tables.md — so something has to choose one.
    expect(categoryNamed('Desserts')->position)->toBeGreaterThan($last->position);
});

it('deletes a category from the arrangement, its branch with it', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $subCategory = MenuCategory::factory()->under($category)->create();
    $menuItem = MenuItem::factory()->inCategory($subCategory)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    arrangementOf($menu)->callAction(TestAction::make('delete')->table(categoryRow($category)));

    // The subdivisions and the items go with it, by the cascades on the
    // foreign keys rather than by anything this action does.
    expect(MenuCategory::query()->withoutGlobalScopes()->whereKey($category->getKey())->exists())->toBeFalse()
        ->and(MenuCategory::query()->withoutGlobalScopes()->whereKey($subCategory->getKey())->exists())->toBeFalse()
        ->and(MenuItem::query()->withoutGlobalScopes()->whereKey($menuItem->getKey())->exists())->toBeFalse();
});

it('keeps the arrangement\'s own actions away from someone who may only read the menu', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $menuItem = MenuItem::factory()->inCategory($category)->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    // Reading the shape of a menu is menu.view, so the page opens and so does
    // a category; everything that changes either is menu.manage and is not there.
    arrangementOf($menu)
        ->assertOk()
        ->assertActionHidden(TestAction::make('createCategory')->table())
        ->assertActionHidden(TestAction::make('rename')->table(categoryRow($category)))
        ->assertActionHidden(TestAction::make('createSubCategory')->table(categoryRow($category)))
        ->assertActionHidden(TestAction::make('moveToMenu')->table(categoryRow($category)))
        ->assertActionHidden(TestAction::make('delete')->table(categoryRow($category)))
        ->assertActionVisible(TestAction::make('open')->table(categoryRow($category)));

    itemsOf($category)
        ->assertOk()
        ->assertCanSeeTableRecords([$menuItem])
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($menuItem))
        ->assertActionHidden(TestAction::make('delete')->table($menuItem));
});

it('refuses a third level', function (): void {
    $menu = Menu::factory()->create();
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $subCategory = MenuCategory::factory()->under($category)->create();

    // A menu is read as sections and subdivisions; no constraint can say that,
    // so MenuCategoryObserver does.
    expect(fn () => MenuCategory::factory()->under($subCategory)->create())
        ->toThrow(LogicException::class, 'two levels deep');
});

it('refuses a category that is its own parent', function (): void {
    $category = MenuCategory::factory()->create();

    expect(fn () => $category->update(['parent_id' => $category->getKey()]))
        ->toThrow(LogicException::class, 'its own parent');
});

it('separates the two levels for the two tables that show them', function (): void {
    $menu = Menu::factory()->create();
    $section = MenuCategory::factory()->inMenu($menu)->create();
    $subCategory = MenuCategory::factory()->under($section)->create();

    expect($menu->categories()->pluck('id')->all())->toBe([$section->getKey()])
        ->and($menu->subCategories()->pluck('id')->all())->toBe([$subCategory->getKey()])
        ->and($menu->menuCategories()->count())->toBe(2)
        ->and($section->isTopLevel())->toBeTrue()
        ->and($section->children()->pluck('id')->all())->toBe([$subCategory->getKey()]);
});

it('refuses a sub-category name the same category already uses', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    MenuCategory::factory()->under($category)->create(['name' => [Locale::English->value => 'Chicken']]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    arrangementOf($menu)
        ->callAction(TestAction::make('createSubCategory')->table(categoryRow($category)), [
            'parent_id' => $category->getKey(),
            'name' => [Locale::English->value => 'Chicken'],
            'is_active' => true,
        ])
        ->assertHasActionErrors(['name.'.Locale::English->value]);
});

it('lets a section and a subdivision of one menu share a name', function (): void {
    $menu = Menu::factory()->create();
    $biryani = MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Biryani']]);
    $curries = MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Curries']]);

    // Uniqueness is per level, so "Biryani › Chicken", "Curries › Chicken" and
    // a top-level "Chicken" are three different things.
    MenuCategory::factory()->under($biryani)->create(['name' => [Locale::English->value => 'Chicken']]);
    MenuCategory::factory()->under($curries)->create(['name' => [Locale::English->value => 'Chicken']]);
    MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Chicken']]);

    expect(MenuCategory::query()->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, 'Chicken')
        ->count())->toBe(3);
});

it('refuses two sections of one menu with the same name', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Starters']]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // No unique index stands behind this: the form is the only thing that refuses it.
    arrangementOf($menu)
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::English->value => 'Starters'],
            'is_active' => true,
        ])
        ->assertHasActionErrors(['name.'.Locale::English->value]);
});

it('refuses a subdivision of a category on another menu, even around the form', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $onLunch = MenuCategory::factory()->inMenu($lunch)->create();

    // MenuCategoryObserver is what stops a branch straddling two menus.
    expect(fn () => MenuCategory::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'menu_id' => $dinner->getKey(),
        'parent_id' => $onLunch->getKey(),
    ]))->toThrow(LogicException::class, 'same menu as its parent');
});

/*
|--------------------------------------------------------------------------
| Filing items
|--------------------------------------------------------------------------
*/

it('files an item under exactly one category, at either level', function (): void {
    $menu = Menu::factory()->create();
    $section = MenuCategory::factory()->inMenu($menu)->create();
    $subCategory = MenuCategory::factory()->under($section)->create();

    $direct = MenuItem::factory()->inCategory($section)->create();
    $nested = MenuItem::factory()->inCategory($subCategory)->create();

    // One column, so there is no pair to disagree — the whole reason the two
    // levels were merged into one table.
    expect($direct->menu_category_id)->toBe($section->getKey())
        ->and($nested->menu_category_id)->toBe($subCategory->getKey())
        ->and($section->menuItems()->pluck('id')->all())->toBe([$direct->getKey()])
        ->and($subCategory->menuItems()->pluck('id')->all())->toBe([$nested->getKey()]);
});

it('reads an item under the branch it sits on', function (): void {
    $menu = Menu::factory()->create();
    $section = MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Biryani']]);
    $subCategory = MenuCategory::factory()->under($section)->create(['name' => [Locale::English->value => 'Chicken']]);

    expect($section->path())->toBe('Biryani')
        ->and($subCategory->load('parent')->path())->toBe('Biryani › Chicken');
});

it('names the branch an item sits on, at either level', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $section = MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Biryani']]);
    $chicken = MenuCategory::factory()->under($section)->create(['name' => [Locale::English->value => 'Chicken']]);

    $direct = MenuItem::factory()->inCategory($section)->create();
    $nested = MenuItem::factory()->inCategory($chicken)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The list is flat now that grouping is gone, so the column has to say
    // where an item sits — and a subdivision on its own says nothing about
    // which section it belongs to.
    Livewire::test(ListMenuItems::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$direct, $nested])
        ->assertSee('Biryani › Chicken');

    expect($nested->fresh()->load('menuCategory.parent')->menuCategory->path())->toBe('Biryani › Chicken')
        ->and($direct->fresh()->load('menuCategory.parent')->menuCategory->path())->toBe('Biryani');
});

it('filters items by menu, and by a category within it', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $drinks = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $starters = MenuCategory::factory()->inMenu($lunch)->create();
    $chicken = MenuCategory::factory()->under($starters)->create();
    $hot = MenuCategory::factory()->inMenu($drinks)->create();

    $inStarters = MenuItem::factory()->inCategory($starters)->create();
    $inChicken = MenuItem::factory()->inCategory($chicken)->create();
    $inDrinks = MenuItem::factory()->inCategory($hot)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // An item reaches its menu through its category, so the menu filter is a
    // relationship rather than a column of its own.
    Livewire::test(ListMenuItems::class)
        ->filterTable('menu', $lunch->getKey())
        ->assertCanSeeTableRecords([$inStarters, $inChicken])
        ->assertCanNotSeeTableRecords([$inDrinks]);

    // A category brings the items of its sub-categories with it.
    Livewire::test(ListMenuItems::class)
        ->filterTable('category', $starters->getKey())
        ->assertCanSeeTableRecords([$inStarters, $inChicken])
        ->assertCanNotSeeTableRecords([$inDrinks]);

    // A sub-category narrows to its own items.
    Livewire::test(ListMenuItems::class)
        ->filterTable('sub_category', $chicken->getKey())
        ->assertCanSeeTableRecords([$inChicken])
        ->assertCanNotSeeTableRecords([$inStarters, $inDrinks]);
});

it('splits the items page into tabs by kind, by stock, by featuring and by diet mark', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))->create();
    $water = MenuItem::factory()->inCategory($category)->create(['diets' => [Diet::Vegetarian], 'is_featured' => true]);
    // Both marks, which is what most vegetarian items carry.
    $idli = MenuItem::factory()->inCategory($category)->marked(Diet::Vegetarian, Diet::Vegan)->create();
    $omelette = MenuItem::factory()->inCategory($category)->create(['diets' => [Diet::Egg], 'availability' => ItemAvailability::OutOfStock]);
    $chicken = MenuItem::factory()->inCategory($category)->create(['diets' => [Diet::NonVegetarian]]);
    $pillow = MenuItem::factory()->inCategory($category)->service()->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Whether an item is a service request, or featured, is what its tab says,
    // so the table spends no column on either; nor on the GST rate, which the
    // project owner had taken off the list.
    $page = Livewire::test(ListMenuItems::class)
        ->assertCanSeeTableRecords([$water, $idli, $omelette, $chicken, $pillow])
        ->assertTableColumnDoesNotExist('is_service_request')
        ->assertTableColumnDoesNotExist('is_featured')
        ->assertTableColumnDoesNotExist('tax_rate');

    $page->set('activeTab', 'items')
        ->assertCanSeeTableRecords([$water, $idli, $omelette, $chicken])
        ->assertCanNotSeeTableRecords([$pillow]);

    $page->set('activeTab', 'service_requests')
        ->assertCanSeeTableRecords([$pillow])
        ->assertCanNotSeeTableRecords([$water, $idli, $omelette, $chicken]);

    $page->set('activeTab', 'out_of_stock')
        ->assertCanSeeTableRecords([$omelette])
        ->assertCanNotSeeTableRecords([$water, $idli, $chicken, $pillow]);

    $page->set('activeTab', 'featured')
        ->assertCanSeeTableRecords([$water])
        ->assertCanNotSeeTableRecords([$idli, $omelette, $chicken, $pillow]);

    // A tab asks whether an item carries its mark, not whether that is the only
    // one it carries, so the vegan idli is on the veg tab as well.
    $page->set('activeTab', 'veg')
        ->assertCanSeeTableRecords([$water, $idli])
        ->assertCanNotSeeTableRecords([$omelette, $chicken, $pillow]);

    // Vegan is still a tab of its own, and the merely vegetarian item is not on it.
    $page->set('activeTab', 'vegan')
        ->assertCanSeeTableRecords([$idli])
        ->assertCanNotSeeTableRecords([$water, $omelette, $chicken, $pillow]);

    $page->set('activeTab', 'egg')
        ->assertCanSeeTableRecords([$omelette])
        ->assertCanNotSeeTableRecords([$water, $idli, $chicken, $pillow]);

    $page->set('activeTab', 'non_veg')
        ->assertCanSeeTableRecords([$chicken])
        ->assertCanNotSeeTableRecords([$water, $idli, $omelette, $pillow]);

    // Every badge, from one query. Filament hands a badge back as a string.
    // The diet counts overlap: the idli is counted as veg and as vegan.
    expect(collect($page->instance()->getTabs())->map->getBadge()->all())->toEqual([
        'all' => 5,
        'items' => 4,
        'service_requests' => 1,
        'out_of_stock' => 1,
        'featured' => 1,
        'veg' => 2,
        'vegan' => 1,
        'egg' => 1,
        'non_veg' => 1,
    ]);
});

it('offers only the chosen category\'s sub-categories once a category is filtered', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $starters = MenuCategory::factory()->inMenu($lunch)->create();
    $chicken = MenuCategory::factory()->under($starters)->create();
    $mains = MenuCategory::factory()->inMenu($lunch)->create();
    MenuCategory::factory()->under($mains)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    $offered = Livewire::test(ListMenuItems::class)
        ->filterTable('category', $starters->getKey())
        ->instance()
        ->getTable()
        ->getFilter('sub_category')
        ->getOptions();

    expect(array_keys($offered))->toBe([$chicken->getKey()]);
});

it('offers only the chosen menu\'s categories once a menu is filtered', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $drinks = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $starters = MenuCategory::factory()->inMenu($lunch)->create();
    $chicken = MenuCategory::factory()->under($starters)->create();
    $hot = MenuCategory::factory()->inMenu($drinks)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    $offered = Livewire::test(ListMenuItems::class)
        ->filterTable('menu', $lunch->getKey())
        ->instance()
        ->getTable()
        ->getFilter('category')
        ->getOptions();

    // Only the chosen menu's top level, and nothing from the other menu — the
    // menu filter has already excluded those rows. Its sub-categories are the
    // Sub-category filter's.
    expect(array_keys($offered))->toBe([$starters->getKey()])
        ->and($chicken->parent_id)->toBe($starters->getKey())
        ->and(array_keys($offered))->not->toContain($hot->getKey());
});

it('reads the items list menu by menu, section by section', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $first = MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $second = MenuCategory::factory()->inMenu($menu)->create(['position' => 1]);
    $nested = MenuCategory::factory()->under($first)->create(['position' => 0]);

    $inFirst = MenuItem::factory()->inCategory($first)->create(['position' => 0]);
    $inNested = MenuItem::factory()->inCategory($nested)->create(['position' => 0]);
    $inSecond = MenuItem::factory()->inCategory($second)->create(['position' => 0]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // A section's own items come before its subdivisions', and the next
    // section follows — the order grouping used to imply.
    Livewire::test(ListMenuItems::class)
        ->assertCanSeeTableRecords([$inFirst, $inNested, $inSecond], inOrder: true);
});

it('orders the items list without touching a translated json column', function (): void {
    // Ordering by a translated column orders whole JSON documents rather than
    // names, and while the column was plain json Postgres had no ordering
    // operator for it at all and 500'd. Inspecting the compiled SQL catches both.
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    MenuItem::factory()->inCategory(MenuCategory::factory()->inMenu($menu)->create())->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // getQuery() is the query before sorting, so the ordering is read off the
    // default sort itself — Filament hands it the query and takes back the
    // ordered builder.
    $sorted = Livewire::test(ListMenuItems::class)
        ->instance()
        ->getTable()
        ->getDefaultSort(MenuItem::query(), 'asc');

    expect($sorted)->toBeInstanceOf(Builder::class);

    $sql = $sorted->toSql();

    expect($sql)->toContain('order by')
        ->toContain('position')
        ->and($sql)->not->toContain('name ->>');
});

/*
|--------------------------------------------------------------------------
| Moving a subdivision between sections
|--------------------------------------------------------------------------
*/

it('moves a sub-category under another section, items and all', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $from = MenuCategory::factory()->inMenu($menu)->create();
    $to = MenuCategory::factory()->inMenu($menu)->create();
    $chicken = MenuCategory::factory()->under($from)->create();

    $moving = MenuItem::factory()->inCategory($chicken)->create();
    $staying = MenuItem::factory()->inCategory($from)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    arrangementOf($menu)
        ->callAction(TestAction::make('rename')->table(categoryRow($chicken)), [
            'parent_id' => $to->getKey(),
            'name' => $chicken->getTranslations('name'),
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    // One write. The items are untouched because they name the subdivision,
    // never its parent — which is exactly what merging the tables bought.
    expect($chicken->refresh()->parent_id)->toBe($to->getKey())
        ->and($moving->refresh()->menu_category_id)->toBe($chicken->getKey())
        ->and($staying->refresh()->menu_category_id)->toBe($from->getKey());
});

it('offers only the sections of this menu as a sub-category\'s parent', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $otherMenu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $from = MenuCategory::factory()->inMenu($menu)->create();
    $sibling = MenuCategory::factory()->inMenu($menu)->create();
    $elsewhere = MenuCategory::factory()->inMenu($otherMenu)->create();
    $nested = MenuCategory::factory()->under($sibling)->create();

    $chicken = MenuCategory::factory()->under($from)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    arrangementOf($menu)
        ->mountAction(TestAction::make('rename')->table(categoryRow($chicken)))
        ->assertSchemaComponentExists('parent_id', checkComponentUsing: function ($component) use ($from, $sibling, $elsewhere, $nested): bool {
            $offered = array_keys($component->getOptions());

            // The one it already sits under is offered too — this is an edit
            // form, so leaving the field alone has to be possible.
            expect($offered)->toContain($sibling->getKey())
                ->toContain($from->getKey())
                // A sub-category never changes menus, and never nests under
                // another sub-category.
                ->and($offered)->not->toContain($elsewhere->getKey())
                ->and($offered)->not->toContain($nested->getKey());

            return true;
        });
});

it('refuses to re-parent a sub-category onto another menu, even around the form', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $chicken = MenuCategory::factory()->under(MenuCategory::factory()->inMenu($lunch)->create())->create();
    $target = MenuCategory::factory()->inMenu($dinner)->create();

    // The form never offers a category from another menu, so this is the
    // backstop under it.
    expect(fn () => $chicken->update(['parent_id' => $target->getKey()]))
        ->toThrow(LogicException::class, 'same menu as its parent');
});

it('refuses to move a section as though it were a subdivision', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $chicken = MenuCategory::factory()->under(MenuCategory::factory()->inMenu($lunch)->create())->create();

    app(MoveCategoryToMenu::class)($chicken, $dinner);
})->throws(LogicException::class, 'not between menus');

it('refuses a move onto a section that already has that name under it', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $from = MenuCategory::factory()->inMenu($menu)->create();
    $to = MenuCategory::factory()->inMenu($menu)->create();

    $moving = MenuCategory::factory()->under($from)->create(['name' => [Locale::English->value => 'Chicken']]);
    MenuCategory::factory()->under($to)->create(['name' => [Locale::English->value => 'Chicken']]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    arrangementOf($menu)
        ->callAction(TestAction::make('rename')->table(categoryRow($moving)), [
            'parent_id' => $to->getKey(),
            'name' => $moving->getTranslations('name'),
            'is_active' => true,
        ])
        ->assertHasActionErrors(['name.'.Locale::English->value]);

    expect($moving->refresh()->parent_id)->toBe($from->getKey());
});

it('carries a section\'s subdivisions onto another menu with it', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $section = MenuCategory::factory()->inMenu($lunch)->create();
    $chicken = MenuCategory::factory()->under($section)->create();
    $menuItem = MenuItem::factory()->inCategory($chicken)->create();

    app(MoveCategoryToMenu::class)($section, $dinner);

    // The subdivision follows by the ON UPDATE CASCADE on (parent_id, menu_id);
    // the item follows because it names the subdivision.
    expect($section->refresh()->menu_id)->toBe($dinner->getKey())
        ->and($chicken->refresh()->menu_id)->toBe($dinner->getKey())
        ->and($chicken->parent_id)->toBe($section->getKey())
        ->and($menuItem->refresh()->menu_category_id)->toBe($chicken->getKey());
});

it('unfeatures the whole branch when a section changes menus', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $section = MenuCategory::factory()->inMenu($lunch)->create();
    $chicken = MenuCategory::factory()->under($section)->create();

    $inSection = MenuItem::factory()->inCategory($section)->create(['is_featured' => true, 'featured_position' => 1]);
    $inSub = MenuItem::factory()->inCategory($chicken)->create(['is_featured' => true, 'featured_position' => 2]);

    app(MoveCategoryToMenu::class)($section, $dinner);

    // The subdivision's items left the menu just as surely as the section's
    // own did, so both stop being led with.
    expect($inSection->refresh()->is_featured)->toBeFalse()
        ->and($inSub->refresh()->is_featured)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Hiding and deleting
|--------------------------------------------------------------------------
*/

it('takes a hidden subdivision\'s items off the menu, and nothing else\'s', function (): void {
    $menu = Menu::factory()->create();
    $section = MenuCategory::factory()->inMenu($menu)->create();
    $hidden = MenuCategory::factory()->under($section)->hidden()->create();
    $showing = MenuCategory::factory()->under($section)->create();

    $inHidden = MenuItem::factory()->inCategory($hidden)->create();
    $inShowing = MenuItem::factory()->inCategory($showing)->create();
    $direct = MenuItem::factory()->inCategory($section)->create();

    $orderable = MenuItem::query()->orderable()->pluck('id')->all();

    expect($orderable)->toContain($inShowing->getKey())
        ->toContain($direct->getKey())
        ->not->toContain($inHidden->getKey());
});

it('takes a hidden section\'s subdivisions off the menu too', function (): void {
    $menu = Menu::factory()->create();
    $section = MenuCategory::factory()->inMenu($menu)->hidden()->create();
    $showing = MenuCategory::factory()->under($section)->create();

    $menuItem = MenuItem::factory()->inCategory($showing)->create();

    // The subdivision is showing, but nothing under a hidden section is.
    expect(MenuItem::query()->orderable()->pluck('id')->all())->not->toContain($menuItem->getKey())
        ->and(MenuCategory::query()->active()->pluck('id')->all())->not->toContain($showing->getKey());
});

it('takes a section\'s subdivisions and their items when it is deleted', function (): void {
    $menu = Menu::factory()->create();
    $section = MenuCategory::factory()->inMenu($menu)->create();
    $chicken = MenuCategory::factory()->under($section)->create();
    $menuItem = MenuItem::factory()->inCategory($chicken)->create();

    $section->delete();

    expect(MenuCategory::query()->withoutGlobalScopes()->find($chicken->getKey()))->toBeNull()
        ->and(MenuItem::query()->withoutGlobalScopes()->find($menuItem->getKey()))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Rearranging the outline
|--------------------------------------------------------------------------
*/

it('rearranges the sections of a menu by dragging them', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $first = MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $second = MenuCategory::factory()->inMenu($menu)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    arrangementOf($menu)->call('reorderTable', [categoryRow($second), categoryRow($first)]);

    expect($second->refresh()->position)->toBeLessThan($first->refresh()->position);
});

it('puts a drag handle on every row of the outline, and no item on it', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $subCategory = MenuCategory::factory()->under($category)->create();
    MenuItem::factory()->inCategory($category)->create(['is_featured' => true]);
    MenuCombo::factory()->onMenu($menu)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The table is custom data, so Filament's drag is wired to the `__key` of
    // each record array rather than to a model. Asserting the write works says
    // nothing about whether a handle was ever rendered to start it.
    $html = arrangementOf($menu)->call('toggleTableReordering')->html();

    expect($html)->toContain('x-sortable-item="featured"')
        ->toContain('x-sortable-item="combos"')
        ->toContain('x-sortable-item="'.categoryRow($category).'"')
        ->toContain('x-sortable-item="'.categoryRow($subCategory).'"')
        // What is inside a category is put in order in the table its row
        // opens. An item on this list could be dropped among another
        // category's rows, which is what the project owner asked to be rid of.
        ->not->toContain('x-sortable-item="item-')
        ->not->toContain('x-sortable-item="featured-');
});

it('tells the page which list each row may be dragged within', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $starters = MenuCategory::factory()->inMenu($menu)->create();
    $chicken = MenuCategory::factory()->under($starters)->create();
    MenuItem::factory()->inCategory($starters)->create(['is_featured' => true]);
    MenuCombo::factory()->onMenu($menu)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The page's drag guard refuses a drop outside a row's own list while the
    // row is still being dragged, and it knows the lists only from these —
    // the same lists ApplyMenuArrangement renumbers.
    $lists = collect(arrangementOf($menu)->instance()->getTable()->getRecords()->all())
        ->map(fn (array $row): string => $row['list'])
        ->all();

    expect($lists)->toBe([
        'featured' => 'top',
        'combos' => 'top',
        categoryRow($starters) => 'top',
        categoryRow($chicken) => 'sub-'.$starters->getKey(),
    ]);

    expect(arrangementOf($menu)->html())
        ->toContain('menu-list--sub-'.$starters->getKey())
        ->toContain('menu-row--sub_category')
        ->toContain('menu-row--featured');
});

it('drags the featured and combo rows in among the categories', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $starters = MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $desserts = MenuCategory::factory()->inMenu($menu)->create(['position' => 1]);
    MenuItem::factory()->inCategory($starters)->create(['is_featured' => true]);
    MenuCombo::factory()->onMenu($menu)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The rails share the categories' number space, so moving one is the same
    // kind of drag as moving a category. Neither had a row before this.
    arrangementOf($menu)->call('reorderTable', [
        categoryRow($starters),
        'combos',
        categoryRow($desserts),
        'featured',
    ]);

    $rails = MenuRail::query()
        ->where('menu_id', $menu->getKey())
        ->get()
        ->mapWithKeys(fn (MenuRail $rail): array => [$rail->type->value => $rail->position])
        ->all();

    expect($starters->refresh()->position)->toBe(0)
        ->and($rails)->toEqual([MenuRailType::Combos->value => 1, MenuRailType::Featured->value => 3])
        ->and($desserts->refresh()->position)->toBe(2);
});

it('leaves an empty row where it was when the rows around it are dragged', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $starters = MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $desserts = MenuCategory::factory()->inMenu($menu)->create(['position' => 1]);
    MenuCombo::factory()->onMenu($menu)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Nothing is featured, so the featured row is neither drawn nor sent. It
    // must not be pushed to the bottom of the menu for having been left out.
    arrangementOf($menu)->call('reorderTable', [
        'combos',
        categoryRow($desserts),
        categoryRow($starters),
    ]);

    $categories = MenuCategory::query()->where('menu_id', $menu->getKey())->topLevel()->inMenuOrder()->get();
    $rails = MenuRail::query()->where('menu_id', $menu->getKey())->get();

    $order = array_map(
        fn (MenuRail|MenuCategory $entry): string|int => $entry instanceof MenuRail ? $entry->type->value : $entry->getKey(),
        $menu->readingOrder($categories, $rails),
    );

    expect($order)->toBe([MenuRailType::Featured->value, MenuRailType::Combos->value, $desserts->getKey(), $starters->getKey()])
        // The featured row is still at the top it never left, so it still has no row.
        ->and($rails->map(fn (MenuRail $rail): MenuRailType => $rail->type)->all())->toBe([MenuRailType::Combos]);
});

it('rearranges subdivisions within their own section', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $section = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuCategory::factory()->under($section)->create(['position' => 0]);
    $second = MenuCategory::factory()->under($section)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    arrangementOf($menu)->call('reorderTable', [categoryRow($second), categoryRow($first)]);

    expect($second->refresh()->position)->toBeLessThan($first->refresh()->position);
});

it('keeps rearranging away from someone who may only read the menu', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $section = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuCategory::factory()->under($section)->create(['position' => 0]);
    $second = MenuCategory::factory()->under($section)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // reorderTable() short-circuits on the reorder() policy method, so
    // asserting the button is hidden would prove nothing.
    arrangementOf($menu)->call('reorderTable', [categoryRow($second), categoryRow($first)]);

    expect($first->refresh()->position)->toBe(0)
        ->and($second->refresh()->position)->toBe(1);
});

it('shows both levels of this menu and nothing from another tenant', function (): void {
    $mine = Tenant::factory()->create();
    $myMenu = Menu::factory()->create(['tenant_id' => $mine->getKey()]);
    $mySection = MenuCategory::factory()->inMenu($myMenu)->create();
    $mySub = MenuCategory::factory()->under($mySection)->create();

    $theirs = Tenant::factory()->create();
    $theirMenu = Menu::factory()->create(['tenant_id' => $theirs->getKey()]);
    $theirSection = MenuCategory::factory()->inMenu($theirMenu)->create();
    $theirSub = MenuCategory::factory()->under($theirSection)->create();

    enterTenantPanel($mine, RoleEnum::Owner);

    // One table holding both levels is the point of this screen; the rows it
    // holds are the ones scoped to this menu, whichever level they sit at.
    $rows = array_keys(arrangementOf($myMenu)->instance()->getTable()->getRecords()->all());

    expect($rows)->toContain(categoryRow($mySection))
        ->toContain(categoryRow($mySub))
        ->and($rows)->not->toContain(categoryRow($theirSection))
        ->and($rows)->not->toContain(categoryRow($theirSub));
});

it('reads the menu page in the same number of queries however much is on it', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    // A branch of every kind: a category, a sub-category, an item in each, one
    // of them featured, and a combo with something in it.
    $addBranch = function () use ($menu): void {
        $category = MenuCategory::factory()->inMenu($menu)->create();
        $subCategory = MenuCategory::factory()->under($category)->create();
        $featured = MenuItem::factory()->inCategory($category)->create(['is_featured' => true]);
        MenuItem::factory()->inCategory($subCategory)->create();
        MenuComboItem::factory()->pairing(MenuCombo::factory()->onMenu($menu)->create(), $featured)->create();
    };

    $queriesToRender = function (Closure $render): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $render();

        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $page = fn () => arrangementOf($menu)->assertOk();
    // The featured items name the category each is filed under, which is the
    // one table here reading a relationship per row.
    $featured = fn () => featuredOf($menu)->assertOk();

    $addBranch();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The first render warms what a request caches, the permissions among them.
    $queriesToRender($page);
    $queriesToRender($featured);
    $pageWithOneBranch = $queriesToRender($page);
    $featuredWithOneBranch = $queriesToRender($featured);

    foreach (range(1, 4) as $ignored) {
        $addBranch();
    }

    // A query per row would add dozens here; each table asks once per kind of row.
    expect($queriesToRender($page))->toBe($pageWithOneBranch)
        ->and($queriesToRender($featured))->toBe($featuredWithOneBranch);
});

/*
|--------------------------------------------------------------------------
| What a row holds, in a table of its own
|--------------------------------------------------------------------------
|
| The outline lists no items. A category's row opens a table of its items, and
| the Featured items and Combos rows open theirs; each is ordered, added to,
| edited and emptied there.
|
*/

it('draws the featured and combo rows only once there is something in them', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $menuItem = MenuItem::factory()->inCategory($category)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // An empty row used to head every menu saying "No combos"; the header's
    // buttons open an empty one instead.
    expect(array_keys(arrangementOf($menu)->instance()->getTable()->getRecords()->all()))
        ->toBe([categoryRow($category)]);

    arrangementOf($menu)
        ->assertActionVisible(TestAction::make('openFeatured')->table())
        ->assertActionVisible(TestAction::make('openCombos')->table());

    $menuItem->update(['is_featured' => true]);
    MenuCombo::factory()->onMenu($menu)->create();

    expect(array_keys(arrangementOf($menu)->instance()->getTable()->getRecords()->all()))
        ->toBe(['featured', 'combos', categoryRow($category)]);
});

it('opens what a row holds in a table of its own', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    MenuItem::factory()->inCategory($category)->create(['name' => [Locale::English->value => 'Chicken 65'], 'is_featured' => true]);
    MenuCombo::factory()->onMenu($menu)->create(['name' => [Locale::English->value => 'Burger Meal']]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The outline lists neither name, so each one appearing is the table
    // inside the modal, rendered.
    arrangementOf($menu)
        ->assertDontSee('Chicken 65')
        ->assertDontSee('Burger Meal');

    // Mounting an action draws only its modal, sent as a partial of the
    // response, and the table inside it is drawn there with it. A later full
    // render would show that table as an empty placeholder: Livewire leaves a
    // child it has already mounted to the browser.
    $modalOf = fn (TestAction $action): string => (string) (arrangementOf($menu)->mountAction($action)->effects['partials']['action-modals'] ?? '');

    expect($modalOf(TestAction::make('open')->table(categoryRow($category))))->toContain('Chicken 65')
        ->and($modalOf(TestAction::make('open')->table('featured')))->toContain('Chicken 65')
        ->and($modalOf(TestAction::make('openCombos')->table()))->toContain('Burger Meal');
});

it('adds an item to the category or sub-category it was opened from', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $section = MenuCategory::factory()->inMenu($menu)->create();
    $chicken = MenuCategory::factory()->under($section)->create();
    $existing = MenuItem::factory()->inCategory($chicken)->create(['position' => 4]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    itemsOf($chicken)
        ->callAction(TestAction::make('create')->table(), [
            'name' => [Locale::English->value => 'Chicken 65'],
            'price' => '220',
        ])
        ->assertHasNoActionErrors();

    $menuItem = MenuItem::query()->withoutGlobalScopes()->where('name->'.Locale::English->value, 'Chicken 65')->sole();

    // Only a name and a price were typed. The category came from the table it
    // was added in, and the form kept the rest of its defaults — which filling
    // it with the category instead would have skipped.
    expect($menuItem->menu_category_id)->toBe($chicken->getKey())
        ->and($menuItem->tenant_id)->toBe($tenant->getKey())
        ->and($menuItem->price)->toBe(22000)
        ->and($menuItem->availability)->toBe(ItemAvailability::Available)
        ->and($menuItem->dietMark())->toBe(Diet::Vegetarian)
        ->and($menuItem->position)->toBeGreaterThan($existing->position);
});

it('starts a sub-category under the category its button was pressed on, showing', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    MenuCategory::factory()->inMenu($menu)->create();
    $biryani = MenuCategory::factory()->inMenu($menu)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    arrangementOf($menu)
        ->callAction(TestAction::make('createSubCategory')->table(categoryRow($biryani)), [
            'name' => [Locale::English->value => 'Chicken'],
        ])
        ->assertHasNoActionErrors();

    $chicken = categoryNamed('Chicken');

    expect($chicken->parent_id)->toBe($biryani->getKey())
        ->and($chicken->is_active)->toBeTrue();
});

it('edits an item in its category\'s table, keeping the add-on groups and tax nobody touched', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $menuItem = MenuItem::factory()->inCategory($category)->taxedAt(1200)->create(['price' => 10000]);
    $link = MenuItemAddOnGroup::factory()
        ->linking($menuItem, MenuAddOnGroup::factory()->ofTenant($tenant)->create())
        ->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The add-on groups are a repeater bound to the item's links. Saving a new
    // price must write the item and leave the links it already has alone.
    itemsOf($category)
        ->callAction(TestAction::make('edit')->table($menuItem), [
            'price' => '150',
        ])
        ->assertHasNoActionErrors();

    expect($menuItem->refresh()->price)->toBe(15000)
        ->and($menuItem->tax_rate)->toBe(1200)
        ->and($menuItem->addOnGroupLinks()->pluck('id')->all())->toBe([$link->getKey()]);
});

it('offers an add-on group on an item from its category\'s table', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $menuItem = MenuItem::factory()->inCategory($category)->create();
    $spice = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: true, max: 1)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    itemsOf($category)
        ->callAction(TestAction::make('edit')->table($menuItem), [
            'addOnGroupLinks' => [
                ['menu_add_on_group_id' => $spice->getKey()],
            ],
        ])
        ->assertHasNoActionErrors();

    $link = $menuItem->addOnGroupLinks()->sole();

    expect($link->menu_add_on_group_id)->toBe($spice->getKey())
        ->and($link->tenant_id)->toBe($tenant->getKey());
});

it('deletes an item from its category\'s table', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $menuItem = MenuItem::factory()->inCategory($category)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    itemsOf($category)->callAction(TestAction::make('delete')->table($menuItem));

    expect(MenuItem::query()->withoutGlobalScopes()->whereKey($menuItem->getKey())->exists())->toBeFalse();
});

it('features items from the featured items\' table, after the ones already featured', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    $already = MenuItem::factory()->inCategory($category)->create(['is_featured' => true, 'featured_position' => 4]);
    $first = MenuItem::factory()->inCategory($category)->create();
    $second = MenuItem::factory()->inCategory($category)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    featuredOf($menu)
        ->callAction(TestAction::make('featureItems')->table(), [
            'items' => [$second->getKey(), $first->getKey()],
        ])
        ->assertHasNoActionErrors();

    // In the order they were picked, behind what the menu already led with.
    expect($second->refresh()->is_featured)->toBeTrue()
        ->and($second->featured_position)->toBe(5)
        ->and($first->refresh()->featured_position)->toBe(6)
        ->and($already->refresh()->featured_position)->toBe(4);
});

it('takes an item off the featured items, and leaves it on the menu', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $menuItem = MenuItem::factory()->inCategory($category)->create(['is_featured' => true, 'featured_position' => 2]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    featuredOf($menu)->callAction(TestAction::make('unfeature')->table($menuItem));

    expect($menuItem->refresh()->is_featured)->toBeFalse()
        ->and($menuItem->featured_position)->toBe(0)
        ->and($menuItem->menu_category_id)->toBe($category->getKey());
});

it('does not offer dragging on the items page at all', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $starters = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuItem::factory()->inCategory($starters)->create(['position' => 0]);
    $second = MenuItem::factory()->inCategory($starters)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // That page spans every menu, where a position means nothing — and
    // reorderTable() short-circuits on the same check, so a request that
    // arrives anyway does nothing.
    $table = Livewire::test(ListMenuItems::class);

    expect($table->instance()->getTable()->isReorderable())->toBeFalse();

    $table->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($first->refresh()->position)->toBe(0)
        ->and($second->refresh()->position)->toBe(1);
});

it('rearranges the items of a category in its own table', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $starters = MenuCategory::factory()->inMenu($menu)->create();
    $desserts = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuItem::factory()->inCategory($starters)->create(['position' => 0]);
    $second = MenuItem::factory()->inCategory($starters)->create(['position' => 1]);
    $untouched = MenuItem::factory()->inCategory($desserts)->create(['position' => 0]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    itemsOf($starters)->call('reorderTable', [$second->getKey(), $first->getKey()]);

    // Only the items that moved against each other are renumbered: every
    // category's items are ordered within that category.
    expect($second->refresh()->position)->toBeLessThan($first->refresh()->position)
        ->and($untouched->refresh()->position)->toBe(0);
});

it('rearranges items inside a sub-category the same way', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $section = MenuCategory::factory()->inMenu($menu)->create();
    $chicken = MenuCategory::factory()->under($section)->create();

    $first = MenuItem::factory()->inCategory($chicken)->create(['position' => 0]);
    $second = MenuItem::factory()->inCategory($chicken)->create(['position' => 1]);
    $inParent = MenuItem::factory()->inCategory($section)->create(['position' => 0]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    itemsOf($chicken)->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($second->refresh()->position)->toBeLessThan($first->refresh()->position)
        ->and($inParent->refresh()->position)->toBe(0);
});

it('leaves an item under the category it belongs to when its key is sent to another', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $starters = MenuCategory::factory()->inMenu($menu)->create();
    $desserts = MenuCategory::factory()->inMenu($menu)->create();

    $menuItem = MenuItem::factory()->inCategory($starters)->create(['position' => 5]);
    $dessert = MenuItem::factory()->inCategory($desserts)->create(['position' => 0]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // A category's table orders that category's items and nothing else: the
    // write is scoped to the relationship, so an item of another category sent
    // along is neither renumbered nor re-filed. Re-filing an item is an edit on
    // its own form — see .ai/rules/actions-menus.md.
    itemsOf($desserts)->call('reorderTable', [$menuItem->getKey(), $dessert->getKey()]);

    expect($menuItem->refresh()->menu_category_id)->toBe($starters->getKey())
        ->and($menuItem->position)->toBe(5);
});

it('keeps rearranging items away from someone who may only read the menu', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuItem::factory()->inCategory($category)->create(['position' => 0]);
    $second = MenuItem::factory()->inCategory($category)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // A category's items can be read by anyone who may read the menu, so the
    // reorder() policy is the only thing standing between them and a drag —
    // and reorderTable() short-circuits on exactly that call.
    itemsOf($category)->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($first->refresh()->position)->toBe(0)
        ->and($second->refresh()->position)->toBe(1);
});
