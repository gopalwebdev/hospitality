<?php

use App\Enums\Role as RoleEnum;
use App\Enums\StockMovementReason;
use App\Filament\Tenant\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Tenant\Resources\Orders\Pages\ListOrders;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Providers\AppServiceProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| How a date and a time read in the panels
|--------------------------------------------------------------------------
|
| One format across both panels, set once on Table and Schema in
| AppServiceProvider. Filament's own defaults are a 24-hour clock carrying
| seconds, so every ->date(), ->dateTime() and ->time() call site here would
| otherwise read "Sep 20, 2026 08:37:50".
|
| Both halves are covered because they are two separate mechanisms: a table
| column reads its default off Table, an infolist entry off its Schema.
|
*/

it('reads a table column on a 12-hour clock, with no seconds', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $order = Order::factory()->onMenu($menu)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The table defers loading, so its rows are not in the first render.
    $html = Livewire::test(ListOrders::class)
        ->assertOk()
        ->call('loadTable')
        ->html();

    $placed = $order->created_at;

    expect($placed)->not->toBeNull()
        ->and($html)->toContain($placed->translatedFormat(AppServiceProvider::DATE_TIME_FORMAT))
        ->and($html)->not->toContain($placed->format('H:i:s'));
});

it('reads an infolist entry the same way', function (): void {
    $tenant = Tenant::factory()->create();

    $item = MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))->create())
        ->stocked(5)
        ->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    $page = Livewire::test(ListMenuItems::class)
        ->mountAction(TestAction::make('stockHistory')->table($item));

    // The modal comes back as a partial of its own; the component's html() holds none.
    $html = collect($page->effects['partials'] ?? [])
        ->first(fn (mixed $partial, string $key): bool => str_starts_with($key, 'action-modals'));

    $movement = StockMovement::query()
        ->where('menu_item_id', $item->getKey())
        ->latest('id')
        ->firstOrFail();

    expect($movement->reason)->toBe(StockMovementReason::Count)
        ->and($html)->toBeString()
        ->toContain($movement->created_at->translatedFormat(AppServiceProvider::DATE_TIME_FORMAT))
        ->not->toContain($movement->created_at->format('H:i:s'));
});

it('states the formats it sets', function (): void {
    // Pinned as values rather than inferred from a render, so a change to how
    // this application reads a date is a deliberate edit to this line.
    expect(AppServiceProvider::DATE_FORMAT)->toBe('M j, Y')
        ->and(AppServiceProvider::DATE_TIME_FORMAT)->toBe('M j, Y g:i A')
        ->and(AppServiceProvider::TIME_FORMAT)->toBe('g:i A');
});
