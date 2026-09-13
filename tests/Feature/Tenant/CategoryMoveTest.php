<?php

use App\Enums\Locale;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\Menus\Pages\ArrangeMenu;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Moving a category between menus
|--------------------------------------------------------------------------
|
| A tenant splitting one card into a lunch and a dinner menu carries whole
| categories across rather than retyping them. See App\Actions\Menus\MoveCategoryToMenu.
|
*/

it('moves a category onto another menu, dishes and all', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $category = MenuCategory::factory()->inMenu($lunch)->create(['name' => [Locale::English->value => 'Starters']]);
    $dish = MenuItem::factory()->inCategory($category)->create();

    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(ArrangeMenu::class, ['record' => $lunch->getKey()])
        ->callAction(TestAction::make('moveToMenu')->table('category-'.$category->getKey()), ['menu_id' => $dinner->getKey()])
        ->assertHasNoActionErrors();

    // The dishes hang off the category, not off the menu, so they follow
    // without being rewritten.
    expect($category->refresh()->menu_id)->toBe($dinner->getKey())
        ->and($dish->refresh()->menu_category_id)->toBe($category->getKey())
        ->and($dish->tenant_id)->toBe($tenant->getKey());
});

it('refuses a move onto a menu that already has that name', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $moving = MenuCategory::factory()->inMenu($lunch)->create(['name' => [Locale::English->value => 'Starters']]);
    MenuCategory::factory()->inMenu($dinner)->create(['name' => [Locale::English->value => 'Starters']]);

    enterTenantPanel($tenant, RoleEnum::Admin);

    // Uniqueness is per menu and built on the English name, and nothing but this
    // check refuses a duplicate.
    Livewire::test(ArrangeMenu::class, ['record' => $lunch->getKey()])
        ->callAction(TestAction::make('moveToMenu')->table('category-'.$moving->getKey()), ['menu_id' => $dinner->getKey()])
        ->assertHasActionErrors(['menu_id']);

    expect($moving->refresh()->menu_id)->toBe($lunch->getKey());
});

it('offers only the menus this category is not already on', function (): void {
    $tenant = Tenant::factory()->create();
    $lunch = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $dinner = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $other = Tenant::factory()->create();
    $theirs = Menu::factory()->create(['tenant_id' => $other->getKey()]);

    $category = MenuCategory::factory()->inMenu($lunch)->create();

    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(ArrangeMenu::class, ['record' => $lunch->getKey()])
        ->mountAction(TestAction::make('moveToMenu')->table('category-'.$category->getKey()))
        ->assertSchemaComponentExists('menu_id', checkComponentUsing: function ($component) use ($lunch, $dinner, $theirs): bool {
            $offered = array_keys($component->getOptions());

            expect($offered)->toContain($dinner->getKey())
                ->and($offered)->not->toContain($lunch->getKey())
                ->and($offered)->not->toContain($theirs->getKey());

            return true;
        });
});

it('keeps the move away from someone who may only read the menu', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->assertActionHidden(TestAction::make('moveToMenu')->table('category-'.$category->getKey()));
});

/*
|--------------------------------------------------------------------------
| Rearranging
|--------------------------------------------------------------------------
*/

it('rearranges categories by dragging them', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $first = MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $second = MenuCategory::factory()->inMenu($menu)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Admin);

    // Filament's own drag and drop hands back the new order of keys; the
    // trigger button only switches the mode it is done in.
    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->call('reorderTable', ['category-'.$second->getKey(), 'category-'.$first->getKey()]);

    expect($second->refresh()->position)->toBeLessThan($first->refresh()->position);
});

it('keeps rearranging away from someone who may only read the menu', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $first = MenuCategory::factory()->inMenu($menu)->create(['position' => 0]);
    $second = MenuCategory::factory()->inMenu($menu)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // reorderTable() short-circuits on the table not being reorderable, which
    // is the reorder() policy method — see .ai/rules/policies.md. Calling it
    // directly is the check that matters: hiding the button is not a guard.
    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])
        ->call('reorderTable', ['category-'.$second->getKey(), 'category-'.$first->getKey()]);

    expect($first->refresh()->position)->toBe(0)
        ->and($second->refresh()->position)->toBe(1);
});
