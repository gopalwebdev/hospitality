<?php

use App\Actions\Menus\MoveCategoryToMenu;
use App\Enums\Locale;
use App\Enums\Role as RoleEnum;
use App\Filament\Restaurant\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Restaurant\Resources\Menus\MenuResource;
use App\Filament\Restaurant\Resources\Menus\Pages\ArrangeMenu;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
 * Open the one table a menu is arranged on.
 *
 * Both levels of category, the dishes in each and the two rails are rows of
 * this single table now — see MenuArrangementTable.
 */
function arrangementOf(Menu $menu): Testable
{
    return Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()]);
}

/**
 * The key the arrangement table gives a category's row.
 */
function categoryRow(MenuCategory $category): string
{
    return 'category-'.$category->getKey();
}

/**
 * The key the arrangement table gives a dish's row.
 */
function dishRow(MenuItem $dish): string
{
    return 'item-'.$dish->getKey();
}

/*
|--------------------------------------------------------------------------
| Two levels, one table
|--------------------------------------------------------------------------
|
| A category with no parent is a section of the menu; one with a parent is a
| subdivision of that section. A dish names exactly one of them.
|
*/

it('subdivides a category from the menu page', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    arrangementOf($menu)
        ->callAction(TestAction::make('createSubCategory')->table(categoryRow($category)), [
            'parent_id' => $category->getKey(),
            'name' => [Locale::English->value => 'Chicken', Locale::Tamil->value => 'சிக்கன்'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $subCategory = categoryNamed('Chicken');

    // The menu and the restaurant are both derived rather than typed: the
    // relation sets menu_id, and MenuCategory::booted() takes the tenant.
    expect($subCategory->parent_id)->toBe($category->getKey())
        ->and($subCategory->menu_id)->toBe($menu->getKey())
        ->and($subCategory->tenant_id)->toBe($restaurant->getKey())
        ->and($subCategory->isSubCategory())->toBeTrue()
        ->and($subCategory->getTranslation('name', Locale::Tamil->value))->toBe('சிக்கன்');
});

it('opens its modals the way the browser asks for them', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // callAction() builds its own context, so it cannot catch this: the *page*
    // has a record — the menu — and Filament hands it to any action that has
    // not been given one. Action::getContext() only filters that out by
    // comparing it against the table's model, which custom data has none of, so
    // the menu's id shipped as the row key. Mounting then looked for a row
    // keyed `1` among rows keyed `category-3`, found none, and quietly declined:
    // every button on this page did nothing at all, and nothing was logged.
    $html = (string) $this->get(MenuResource::getUrl('arrange', ['record' => $menu, 'tenant' => $restaurant]))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('mountAction(\'createCategory\', {}, JSON.parse(\'{\u0022table\u0022:true}\')')
        ->toContain('\u0022recordKey\u0022:\u0022'.categoryRow($category).'\u0022');

    // And the mount those handlers make really does open the modal.
    $page = Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->call('mountAction', 'createCategory', [], ['table' => true]);

    expect($page->get('mountedActions'))->toHaveCount(1);

    $page->call('mountAction', 'rename', [], ['recordKey' => categoryRow($category), 'table' => true]);

    expect($page->get('mountedActions'))->toHaveCount(1);
});

it('redraws the arrangement from what was saved once an action has run', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $renamed = MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Starters']]);
    $deleted = MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Doomed']]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $last = MenuCategory::factory()->inMenu($menu)->create(['position' => 1]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    arrangementOf($menu)
        ->callAction(TestAction::make('createCategory')->table(), [
            'name' => [Locale::English->value => 'Desserts'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    // Where a restaurant adding a section looks for it. Positions are never
    // typed — see .ai/rules/tables.md — so something has to choose one.
    expect(categoryNamed('Desserts')->position)->toBeGreaterThan($last->position);
});

it('deletes a category from the arrangement, its branch with it', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $subCategory = MenuCategory::factory()->under($category)->create();
    $dish = MenuItem::factory()->inCategory($subCategory)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    arrangementOf($menu)->callAction(TestAction::make('delete')->table(categoryRow($category)));

    // The subdivisions and the dishes go with it, by the cascades on the
    // foreign keys rather than by anything this action does.
    expect(MenuCategory::query()->withoutGlobalScopes()->whereKey($category->getKey())->exists())->toBeFalse()
        ->and(MenuCategory::query()->withoutGlobalScopes()->whereKey($subCategory->getKey())->exists())->toBeFalse()
        ->and(MenuItem::query()->withoutGlobalScopes()->whereKey($dish->getKey())->exists())->toBeFalse();
});

it('keeps the arrangement\'s own actions away from someone who may only read the menu', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Staff);

    // Reading the shape of a menu is menu.view, so the page opens; everything
    // that changes it is menu.manage and is not on it.
    arrangementOf($menu)
        ->assertOk()
        ->assertActionHidden(TestAction::make('createCategory')->table())
        ->assertActionHidden(TestAction::make('rename')->table(categoryRow($category)))
        ->assertActionHidden(TestAction::make('delete')->table(categoryRow($category)));
});

it('refuses a third level', function (): void {
    $menu = Menu::factory()->create();
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $subCategory = MenuCategory::factory()->under($category)->create();

    // A menu is read as sections and subdivisions; no foreign key can say that,
    // so the model does.
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
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    MenuCategory::factory()->under($category)->create(['name' => [Locale::English->value => 'Chicken']]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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
    // a top-level "Chicken" are three different things — which is what the
    // COALESCE in the expression index is for.
    MenuCategory::factory()->under($biryani)->create(['name' => [Locale::English->value => 'Chicken']]);
    MenuCategory::factory()->under($curries)->create(['name' => [Locale::English->value => 'Chicken']]);
    MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Chicken']]);

    expect(MenuCategory::query()->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, 'Chicken')
        ->count())->toBe(3);
});

it('refuses two sections of one menu with the same name', function (): void {
    $menu = Menu::factory()->create();
    MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Starters']]);

    expect(fn () => MenuCategory::factory()->inMenu($menu)->create([
        'name' => [Locale::English->value => 'Starters'],
    ]))->toThrow(QueryException::class);
});

it('refuses at the database a subdivision of a category on another menu', function (): void {
    $restaurant = Restaurant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $onLunch = MenuCategory::factory()->inMenu($lunch)->create();

    // The (parent_id, menu_id) key is what stops a branch straddling two menus.
    expect(fn () => DB::table('menu_categories')->insert([
        'tenant_id' => $restaurant->getKey(),
        'menu_id' => $dinner->getKey(),
        'parent_id' => $onLunch->getKey(),
        'name' => json_encode([Locale::English->value => 'Smuggled'], JSON_THROW_ON_ERROR),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Filing dishes
|--------------------------------------------------------------------------
*/

it('files a dish under exactly one category, at either level', function (): void {
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

it('reads a dish under the branch it sits on', function (): void {
    $menu = Menu::factory()->create();
    $section = MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Biryani']]);
    $subCategory = MenuCategory::factory()->under($section)->create(['name' => [Locale::English->value => 'Chicken']]);

    expect($section->path())->toBe('Biryani')
        ->and($subCategory->load('parent')->path())->toBe('Biryani › Chicken');
});

it('names the branch a dish sits on, at either level', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $section = MenuCategory::factory()->inMenu($menu)->create(['name' => [Locale::English->value => 'Biryani']]);
    $chicken = MenuCategory::factory()->under($section)->create(['name' => [Locale::English->value => 'Chicken']]);

    $direct = MenuItem::factory()->inCategory($section)->create();
    $nested = MenuItem::factory()->inCategory($chicken)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // The list is flat now that grouping is gone, so the column has to say
    // where a dish sits — and a subdivision on its own says nothing about
    // which section it belongs to.
    Livewire::test(ListMenuItems::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$direct, $nested])
        ->assertSee('Biryani › Chicken');

    expect($nested->fresh()->load('menuCategory.parent')->menuCategory->path())->toBe('Biryani › Chicken')
        ->and($direct->fresh()->load('menuCategory.parent')->menuCategory->path())->toBe('Biryani');
});

it('filters dishes by menu, and by a category within it', function (): void {
    $restaurant = Restaurant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $drinks = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $starters = MenuCategory::factory()->inMenu($lunch)->create();
    $chicken = MenuCategory::factory()->under($starters)->create();
    $hot = MenuCategory::factory()->inMenu($drinks)->create();

    $inStarters = MenuItem::factory()->inCategory($starters)->create();
    $inChicken = MenuItem::factory()->inCategory($chicken)->create();
    $inDrinks = MenuItem::factory()->inCategory($hot)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // A dish reaches its menu through its category, so the menu filter is a
    // relationship rather than a column of its own.
    Livewire::test(ListMenuItems::class)
        ->filterTable('menu', $lunch->getKey())
        ->assertCanSeeTableRecords([$inStarters, $inChicken])
        ->assertCanNotSeeTableRecords([$inDrinks]);

    // A subdivision narrows the list exactly as a section does.
    Livewire::test(ListMenuItems::class)
        ->filterTable('menu_category_id', $chicken->getKey())
        ->assertCanSeeTableRecords([$inChicken])
        ->assertCanNotSeeTableRecords([$inStarters, $inDrinks]);
});

it('offers only the chosen menu\'s categories once a menu is filtered', function (): void {
    $restaurant = Restaurant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $drinks = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $starters = MenuCategory::factory()->inMenu($lunch)->create();
    $chicken = MenuCategory::factory()->under($starters)->create();
    $hot = MenuCategory::factory()->inMenu($drinks)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    $offered = Livewire::test(ListMenuItems::class)
        ->filterTable('menu', $lunch->getKey())
        ->instance()
        ->getTable()
        ->getFilter('menu_category_id')
        ->getOptions();

    // Both levels of the chosen menu, and nothing from the other one — the
    // menu filter has already excluded those rows.
    expect(array_keys($offered))->toContain($starters->getKey())
        ->toContain($chicken->getKey())
        ->not->toContain($hot->getKey());
});

it('reads the dishes list menu by menu, section by section', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey(), 'position' => 0]);

    $first = MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $second = MenuCategory::factory()->inMenu($menu)->create(['position' => 1]);
    $nested = MenuCategory::factory()->under($first)->create(['position' => 0]);

    $inFirst = MenuItem::factory()->inCategory($first)->create(['position' => 0]);
    $inNested = MenuItem::factory()->inCategory($nested)->create(['position' => 0]);
    $inSecond = MenuItem::factory()->inCategory($second)->create(['position' => 0]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // A section's own dishes come before its subdivisions', and the next
    // section follows — the order grouping used to imply.
    Livewire::test(ListMenuItems::class)
        ->assertCanSeeTableRecords([$inFirst, $inNested, $inSecond], inOrder: true);
});

it('orders the dishes list without touching a translated json column', function (): void {
    // Ordering by a translated column orders whole JSON documents rather than
    // names, and while the column was plain json Postgres had no ordering
    // operator for it at all and 500'd. Inspecting the compiled SQL catches both.
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    MenuItem::factory()->inCategory(MenuCategory::factory()->inMenu($menu)->create())->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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

it('moves a sub-category under another section, dishes and all', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $from = MenuCategory::factory()->inMenu($menu)->create();
    $to = MenuCategory::factory()->inMenu($menu)->create();
    $chicken = MenuCategory::factory()->under($from)->create();

    $moving = MenuItem::factory()->inCategory($chicken)->create();
    $staying = MenuItem::factory()->inCategory($from)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    arrangementOf($menu)
        ->callAction(TestAction::make('rename')->table(categoryRow($chicken)), [
            'parent_id' => $to->getKey(),
            'name' => $chicken->getTranslations('name'),
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    // One write. The dishes are untouched because they name the subdivision,
    // never its parent — which is exactly what merging the tables bought.
    expect($chicken->refresh()->parent_id)->toBe($to->getKey())
        ->and($moving->refresh()->menu_category_id)->toBe($chicken->getKey())
        ->and($staying->refresh()->menu_category_id)->toBe($from->getKey());
});

it('offers only the sections of this menu as a sub-category\'s parent', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $otherMenu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $from = MenuCategory::factory()->inMenu($menu)->create();
    $sibling = MenuCategory::factory()->inMenu($menu)->create();
    $elsewhere = MenuCategory::factory()->inMenu($otherMenu)->create();
    $nested = MenuCategory::factory()->under($sibling)->create();

    $chicken = MenuCategory::factory()->under($from)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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

it('refuses at the database to re-parent a sub-category onto another menu', function (): void {
    $restaurant = Restaurant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $chicken = MenuCategory::factory()->under(MenuCategory::factory()->inMenu($lunch)->create())->create();
    $target = MenuCategory::factory()->inMenu($dinner)->create();

    // The form never offers a category from another menu, so this is the
    // backstop under it: (parent_id, menu_id) references (id, menu_id), and a
    // parent on a different menu is not a pair that exists.
    expect(fn () => $chicken->update(['parent_id' => $target->getKey()]))
        ->toThrow(QueryException::class);
});

it('refuses to move a section as though it were a subdivision', function (): void {
    $restaurant = Restaurant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $chicken = MenuCategory::factory()->under(MenuCategory::factory()->inMenu($lunch)->create())->create();

    app(MoveCategoryToMenu::class)($chicken, $dinner);
})->throws(LogicException::class, 'not between menus');

it('refuses a move onto a section that already has that name under it', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $from = MenuCategory::factory()->inMenu($menu)->create();
    $to = MenuCategory::factory()->inMenu($menu)->create();

    $moving = MenuCategory::factory()->under($from)->create(['name' => [Locale::English->value => 'Chicken']]);
    MenuCategory::factory()->under($to)->create(['name' => [Locale::English->value => 'Chicken']]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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
    $restaurant = Restaurant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $section = MenuCategory::factory()->inMenu($lunch)->create();
    $chicken = MenuCategory::factory()->under($section)->create();
    $dish = MenuItem::factory()->inCategory($chicken)->create();

    app(MoveCategoryToMenu::class)($section, $dinner);

    // The subdivision follows by the ON UPDATE CASCADE on (parent_id, menu_id);
    // the dish follows because it names the subdivision.
    expect($section->refresh()->menu_id)->toBe($dinner->getKey())
        ->and($chicken->refresh()->menu_id)->toBe($dinner->getKey())
        ->and($chicken->parent_id)->toBe($section->getKey())
        ->and($dish->refresh()->menu_category_id)->toBe($chicken->getKey());
});

it('unfeatures the whole branch when a section changes menus', function (): void {
    $restaurant = Restaurant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $section = MenuCategory::factory()->inMenu($lunch)->create();
    $chicken = MenuCategory::factory()->under($section)->create();

    $inSection = MenuItem::factory()->inCategory($section)->create(['is_featured' => true, 'featured_position' => 1]);
    $inSub = MenuItem::factory()->inCategory($chicken)->create(['is_featured' => true, 'featured_position' => 2]);

    app(MoveCategoryToMenu::class)($section, $dinner);

    // The subdivision's dishes left the menu just as surely as the section's
    // own did, so both stop being led with.
    expect($inSection->refresh()->is_featured)->toBeFalse()
        ->and($inSub->refresh()->is_featured)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Hiding and deleting
|--------------------------------------------------------------------------
*/

it('takes a hidden subdivision\'s dishes off the menu, and nothing else\'s', function (): void {
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

    $dish = MenuItem::factory()->inCategory($showing)->create();

    // The subdivision is showing, but nothing under a hidden section is.
    expect(MenuItem::query()->orderable()->pluck('id')->all())->not->toContain($dish->getKey())
        ->and(MenuCategory::query()->active()->pluck('id')->all())->not->toContain($showing->getKey());
});

it('takes a section\'s subdivisions and their dishes when it is deleted', function (): void {
    $menu = Menu::factory()->create();
    $section = MenuCategory::factory()->inMenu($menu)->create();
    $chicken = MenuCategory::factory()->under($section)->create();
    $dish = MenuItem::factory()->inCategory($chicken)->create();

    $section->delete();

    expect(MenuCategory::query()->withoutGlobalScopes()->find($chicken->getKey()))->toBeNull()
        ->and(MenuItem::query()->withoutGlobalScopes()->find($dish->getKey()))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Rearranging
|--------------------------------------------------------------------------
*/

it('rearranges the sections of a menu by dragging them', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $first = MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $second = MenuCategory::factory()->inMenu($menu)->create(['position' => 1]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    arrangementOf($menu)->call('reorderTable', [categoryRow($second), categoryRow($first)]);

    expect($second->refresh()->position)->toBeLessThan($first->refresh()->position);
});

it('puts a drag handle on every kind of row', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $subCategory = MenuCategory::factory()->under($category)->create();
    $dish = MenuItem::factory()->inCategory($category)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // The table is custom data, so Filament's drag is wired to the `__key` of
    // each record array rather than to a model. Asserting the write works says
    // nothing about whether a handle was ever rendered to start it.
    $html = arrangementOf($menu)->call('toggleTableReordering')->html();

    expect($html)->toContain('x-sortable-item="featured"')
        ->toContain('x-sortable-item="combos"')
        ->toContain('x-sortable-item="'.categoryRow($category).'"')
        ->toContain('x-sortable-item="'.categoryRow($subCategory).'"')
        ->toContain('x-sortable-item="'.dishRow($dish).'"');
});

it('drags the two rails in among the categories', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $starters = MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $desserts = MenuCategory::factory()->inMenu($menu)->create(['position' => 1]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // The featured dishes and the combos are rows of this table like any
    // category, which is the whole reason they can be moved at all: they share
    // one number space, kept on the menu itself.
    arrangementOf($menu)->call('reorderTable', [
        categoryRow($starters),
        'combos',
        categoryRow($desserts),
        'featured',
    ]);

    $menu->refresh();

    expect($starters->refresh()->position)->toBe(0)
        ->and($menu->combos_position)->toBe(1)
        ->and($desserts->refresh()->position)->toBe(2)
        ->and($menu->featured_position)->toBe(3);
});

it('rearranges subdivisions within their own section', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $section = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuCategory::factory()->under($section)->create(['position' => 0]);
    $second = MenuCategory::factory()->under($section)->create(['position' => 1]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    arrangementOf($menu)->call('reorderTable', [categoryRow($second), categoryRow($first)]);

    expect($second->refresh()->position)->toBeLessThan($first->refresh()->position);
});

it('keeps rearranging away from someone who may only read the menu', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $section = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuCategory::factory()->under($section)->create(['position' => 0]);
    $second = MenuCategory::factory()->under($section)->create(['position' => 1]);

    enterRestaurantPanel($restaurant, RoleEnum::Staff);

    // reorderTable() short-circuits on the reorder() policy method, so
    // asserting the button is hidden would prove nothing.
    arrangementOf($menu)->call('reorderTable', [categoryRow($second), categoryRow($first)]);

    expect($first->refresh()->position)->toBe(0)
        ->and($second->refresh()->position)->toBe(1);
});

it('shows both levels of this menu and nothing from another restaurant', function (): void {
    $mine = Restaurant::factory()->create();
    $myMenu = Menu::factory()->create(['tenant_id' => $mine->getKey()]);
    $mySection = MenuCategory::factory()->inMenu($myMenu)->create();
    $mySub = MenuCategory::factory()->under($mySection)->create();

    $theirs = Restaurant::factory()->create();
    $theirMenu = Menu::factory()->create(['tenant_id' => $theirs->getKey()]);
    $theirSection = MenuCategory::factory()->inMenu($theirMenu)->create();
    $theirSub = MenuCategory::factory()->under($theirSection)->create();

    enterRestaurantPanel($mine, RoleEnum::Admin);

    // One table holding both levels is the point of this screen; the rows it
    // holds are the ones scoped to this menu, whichever level they sit at.
    $rows = array_keys(arrangementOf($myMenu)->instance()->getTable()->getRecords()->all());

    expect($rows)->toContain(categoryRow($mySection))
        ->toContain(categoryRow($mySub))
        ->and($rows)->not->toContain(categoryRow($theirSection))
        ->and($rows)->not->toContain(categoryRow($theirSub));
});

/*
|--------------------------------------------------------------------------
| Rearranging dishes, which is per category
|--------------------------------------------------------------------------
|
| A dish's position is only ever read within its own category, so it is dragged
| where that is legible: under its own heading, on the menu's arrangement. The
| dishes page is a flat list spanning every menu and does not drag at all.
|
*/

it('links a category to its own dishes, narrowed to it', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $starters = MenuCategory::factory()->inMenu($menu)->create();
    MenuCategory::factory()->inMenu($menu)->create();

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    $url = arrangementOf($menu)
        ->instance()
        ->getTable()
        ->getAction('openDishes')
        ->record(['kind' => 'category', 'category_id' => $starters->getKey()])
        ->getUrl();

    // The query key is `filters`, not `tableFilters`: ListRecords binds the
    // property as `#[Url(as: 'filters')]`, and the wrong name is not an error —
    // it silently opens the page showing every dish on every menu.
    expect($url)->toContain('filters%5Bmenu_category_id%5D%5Bvalue%5D='.$starters->getKey());

    expect((string) $this->get($url)->assertOk()->getContent())
        ->toContain('menu_category_id&quot;:[{&quot;value&quot;:&quot;'.$starters->getKey().'&quot;}');
});

it('does not offer dragging on the dishes page at all', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $starters = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuItem::factory()->inCategory($starters)->create(['position' => 0]);
    $second = MenuItem::factory()->inCategory($starters)->create(['position' => 1]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // That page spans every menu, where a position means nothing — and
    // reorderTable() short-circuits on the same check, so a request that
    // arrives anyway does nothing.
    $table = Livewire::test(ListMenuItems::class);

    expect($table->instance()->getTable()->isReorderable())->toBeFalse();

    $table->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($first->refresh()->position)->toBe(0)
        ->and($second->refresh()->position)->toBe(1);
});

it('rearranges the dishes of a category on the arrangement', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $starters = MenuCategory::factory()->inMenu($menu)->create();
    $desserts = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuItem::factory()->inCategory($starters)->create(['position' => 0]);
    $second = MenuItem::factory()->inCategory($starters)->create(['position' => 1]);
    $untouched = MenuItem::factory()->inCategory($desserts)->create(['position' => 0]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    arrangementOf($menu)->call('reorderTable', [
        categoryRow($starters),
        dishRow($second),
        dishRow($first),
        categoryRow($desserts),
        dishRow($untouched),
    ]);

    // Only the dishes that moved against each other are renumbered: every list
    // on the menu is ordered within itself.
    expect($second->refresh()->position)->toBeLessThan($first->refresh()->position)
        ->and($untouched->refresh()->position)->toBe(0);
});

it('rearranges dishes inside a sub-category the same way', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $section = MenuCategory::factory()->inMenu($menu)->create();
    $chicken = MenuCategory::factory()->under($section)->create();

    $first = MenuItem::factory()->inCategory($chicken)->create(['position' => 0]);
    $second = MenuItem::factory()->inCategory($chicken)->create(['position' => 1]);
    $inParent = MenuItem::factory()->inCategory($section)->create(['position' => 0]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    arrangementOf($menu)->call('reorderTable', [
        categoryRow($section),
        dishRow($inParent),
        categoryRow($chicken),
        dishRow($second),
        dishRow($first),
    ]);

    expect($second->refresh()->position)->toBeLessThan($first->refresh()->position)
        ->and($inParent->refresh()->position)->toBe(0);
});

it('leaves a dish under the heading it belongs to when it is dropped elsewhere', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $starters = MenuCategory::factory()->inMenu($menu)->create();
    $desserts = MenuCategory::factory()->inMenu($menu)->create();

    $dish = MenuItem::factory()->inCategory($starters)->create(['position' => 0]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    // Dragging orders a row among its own siblings and nothing else. Re-filing
    // a dish is an edit on its own form, where the parent is a select and the
    // name is revalidated against where it is going — see
    // .ai/rules/actions-menus.md.
    arrangementOf($menu)->call('reorderTable', [
        categoryRow($desserts),
        dishRow($dish),
        categoryRow($starters),
    ]);

    expect($dish->refresh()->menu_category_id)->toBe($starters->getKey());
});

it('keeps rearranging dishes away from someone who may only read the menu', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    $first = MenuItem::factory()->inCategory($category)->create(['position' => 0]);
    $second = MenuItem::factory()->inCategory($category)->create(['position' => 1]);

    enterRestaurantPanel($restaurant, RoleEnum::Staff);

    // The arrangement can be read by anyone who may read the menu, so the
    // reorder() policy is the only thing standing between them and a drag —
    // and reorderTable() short-circuits on exactly that call.
    arrangementOf($menu)->call('reorderTable', [dishRow($second), dishRow($first)]);

    expect($first->refresh()->position)->toBe(0)
        ->and($second->refresh()->position)->toBe(1);
});
