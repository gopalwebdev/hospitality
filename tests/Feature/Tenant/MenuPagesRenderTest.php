<?php

use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Tenant\Resources\Menus\MenuResource;
use App\Filament\Tenant\Resources\Menus\Pages\ArrangeMenu;
use App\Filament\Tenant\Resources\Menus\Pages\EditMenu;
use App\Filament\Tenant\Resources\Menus\Pages\ListMenus;
use App\Filament\Tenant\Resources\Menus\Pages\ManageMenuCombos;
use App\Filament\Tenant\Resources\Menus\Pages\ManageMenuFeaturedItems;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuComboItem;
use App\Models\MenuItem;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

/*
|--------------------------------------------------------------------------
| The panel's menu pages actually render
|--------------------------------------------------------------------------
|
| The tests elsewhere mount one table or one action at a time, which says
| nothing about whether the page holding it renders. These two walk every tab a
| tenant owner opens daily — once with a full tree on them, and once with
| nothing at all, because an empty tenant is what every new one starts as
| and empty states are exactly where a missing relation or a null slips
| through.
|
*/

it('renders every menu page with a full tree on it', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->servedBetween('07:00', '11:00')->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $sub = MenuCategory::factory()->under($category)->create();

    $direct = MenuItem::factory()->inCategory($category)->discounted()->create(['is_featured' => true]);
    $nested = MenuItem::factory()->inCategory($sub)->create();

    $combo = MenuCombo::factory()->onMenu($menu)->discounted()->create();
    MenuComboItem::factory()->pairing($combo, $direct)->quantity(2)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenus::class)->assertOk()->assertCanSeeTableRecords([$menu]);
    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])->assertOk();
    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])->assertOk();
    Livewire::test(ManageMenuFeaturedItems::class, ['record' => $menu->getKey()])->assertOk()->assertCanSeeTableRecords([$direct]);
    Livewire::test(ManageMenuCombos::class, ['record' => $menu->getKey()])->assertOk()->assertCanSeeTableRecords([$combo]);
    Livewire::test(ListMenuItems::class)->assertOk()->assertCanSeeTableRecords([$direct, $nested]);
    Livewire::test(Settings::class)->assertOk();
});

it('renders the menu page for a tenant with nothing on it yet', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ArrangeMenu::class, ['record' => $menu->getKey()])->assertOk();
    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])->assertOk();
    Livewire::test(ManageMenuFeaturedItems::class, ['record' => $menu->getKey()])->assertOk();
    Livewire::test(ManageMenuCombos::class, ['record' => $menu->getKey()])->assertOk();
    Livewire::test(ListMenuItems::class)->assertOk();
});

it('moves between panel pages without a blank browser load', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The panel is a SPA, so a click fetches the next page over Livewire and
    // shows a progress bar while it does — rather than leaving an owner looking
    // at the page they have just left. The guest app gets the same from Inertia,
    // plus a splash for the first load.
    $html = (string) $this->get(MenuResource::getUrl('index', ['tenant' => $tenant]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('wire:navigate');
});

it('serves every menu tab over HTTP, tab strip and all', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    MenuItem::factory()->inCategory($category)->create(['is_featured' => true]);
    MenuCombo::factory()->onMenu($menu)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // A Livewire test renders the component and not the page around it, so it
    // says nothing about the layout, the record sub-navigation or the render
    // hooks. These are the four URLs an owner actually opens.
    foreach (['arrange', 'edit', 'featured', 'combos'] as $tab) {
        $this->get(MenuResource::getUrl($tab, ['record' => $menu, 'tenant' => $tenant]))
            ->assertOk()
            ->assertSee(__('panel.arrangement.title'));
    }
});
