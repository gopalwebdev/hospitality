<?php

use App\Actions\Inventory\ApplyStockChanges;
use App\Actions\Inventory\StockChange;
use App\Enums\Diet;
use App\Enums\ItemAvailability;
use App\Enums\Locale;
use App\Enums\Role as RoleEnum;
use App\Enums\StockMovementReason;
use App\Filament\Tenant\Resources\MenuAddOnGroups\Pages\ManageMenuAddOnGroups;
use App\Filament\Tenant\Resources\MenuItems\Pages\ListMenuItems;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuComboItem;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use App\Models\StockMovement;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Counting stock in the panel
|--------------------------------------------------------------------------
|
| An item's or an option's count is set on its form and adjusted from the
| items tables. A form never writes back a count it did not change, because
| guests may have ordered from it while the form was open.
|
*/

/**
 * An item on a fresh menu of the tenant given, counted when $count is not null.
 */
function countedItemFor(Tenant $tenant, ?int $count): MenuItem
{
    $factory = MenuItem::factory()->inCategory(
        MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))->create(),
    );

    return ($count === null ? $factory : $factory->stocked($count))->create();
}

/**
 * How a row's count got where it is, oldest first: why, by how much, and what was left.
 *
 * @return list<array{0: StockMovementReason, 1: int, 2: int}>
 */
function stockHistoryOf(MenuItem|MenuAddOnOption $row): array
{
    return StockMovement::query()
        ->where($row instanceof MenuItem ? 'menu_item_id' : 'menu_add_on_option_id', $row->getKey())
        ->orderBy('id')
        ->get()
        ->map(fn (StockMovement $movement): array => [$movement->reason, $movement->quantity_change, $movement->quantity_after])
        ->all();
}

it('starts counting an item from its form, and begins its history with that count', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))->create();

    $owner = enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Mutton Dum Biryani'],
            'menu_category_id' => $category->getKey(),
            'diets' => [Diet::NonVegetarian->value],
            'price' => '460',
            'availability' => ItemAvailability::Available->value,
            'stock_quantity' => '12',
        ])
        ->assertHasNoActionErrors();

    $biryani = MenuItem::query()->withoutGlobalScopes()->where('name->'.Locale::English->value, 'Mutton Dum Biryani')->sole();

    expect($biryani->stock_quantity)->toBe(12)
        ->and(stockHistoryOf($biryani))->toBe([[StockMovementReason::Count, 12, 12]])
        ->and(StockMovement::query()->sole()->user_id)->toBe($owner->getKey());
});

it('never writes back a count the edit form did not change, and sets one it did', function (): void {
    $tenant = Tenant::factory()->create();
    $item = countedItemFor($tenant, 10);

    enterTenantPanel($tenant, RoleEnum::Owner);

    $page = Livewire::test(ListMenuItems::class)
        ->mountAction(TestAction::make('edit')->table($item));

    // A guest orders three while the form is open.
    app(ApplyStockChanges::class)([StockChange::take(MenuItem::class, $item->getKey(), 3)], StockMovementReason::OrderPlaced);

    $page->setActionData(['price' => '199'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($item->refresh()->stock_quantity)->toBe(7)
        ->and($item->price)->toBe(19900);

    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($item), ['stock_quantity' => '20'])
        ->assertHasNoActionErrors();

    expect($item->refresh()->stock_quantity)->toBe(20)
        ->and(array_slice(stockHistoryOf($item), -1))->toBe([[StockMovementReason::Count, 13, 20]]);
});

it('marks an item out of stock when its count is set to none, and available again when stock arrives', function (): void {
    $tenant = Tenant::factory()->create();
    $item = countedItemFor($tenant, 4);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('edit')->table($item), ['stock_quantity' => '0'])
        ->assertHasNoActionErrors();

    expect($item->refresh()->availability)->toBe(ItemAvailability::OutOfStock);

    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('adjustStock')->table($item), ['mode' => 'add', 'quantity' => 6, 'note' => 'Evening batch'])
        ->assertHasNoActionErrors();

    expect($item->refresh()->stock_quantity)->toBe(6)
        ->and($item->availability)->toBe(ItemAvailability::Available)
        ->and(StockMovement::query()->latest('id')->firstOrFail()->note)->toBe('Evening batch');
});

it('sets a counted number, and starts counting an item nobody counted', function (): void {
    $tenant = Tenant::factory()->create();
    $counted = countedItemFor($tenant, 9);
    $uncounted = countedItemFor($tenant, null);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListMenuItems::class)
        ->callAction(TestAction::make('adjustStock')->table($counted), ['mode' => 'set', 'quantity' => 4])
        ->assertHasNoActionErrors()
        // Nobody counts it yet, so there is nothing to add to: the count is set.
        ->callAction(TestAction::make('adjustStock')->table($uncounted), ['quantity' => 8])
        ->assertHasNoActionErrors();

    expect(stockHistoryOf($counted))->toBe([[StockMovementReason::Count, 9, 9], [StockMovementReason::Count, -5, 4]])
        ->and(stockHistoryOf($uncounted))->toBe([[StockMovementReason::Count, 8, 8]]);
});

it('reads back how an item\'s count got where it is', function (): void {
    $tenant = Tenant::factory()->create();
    $item = countedItemFor($tenant, 5);

    enterTenantPanel($tenant, RoleEnum::Owner);

    app(ApplyStockChanges::class)([StockChange::add(MenuItem::class, $item->getKey(), 3)], StockMovementReason::Restock, note: 'Evening batch');

    $page = Livewire::test(ListMenuItems::class)
        ->mountAction(TestAction::make('stockHistory')->table($item));

    // The modal comes back as a partial of its own; the component's html() holds none.
    $html = collect($page->effects['partials'] ?? [])
        ->first(fn (mixed $partial, string $key): bool => str_starts_with($key, 'action-modals'));

    expect($html)->toBeString()
        ->toContain(StockMovementReason::Restock->label())
        ->toContain(StockMovementReason::Count->label())
        ->toContain('Evening batch')
        ->toContain('+3');
});

it('lets staff read a count\'s history but not change it', function (): void {
    $tenant = Tenant::factory()->create();
    $item = countedItemFor($tenant, 5);

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(ListMenuItems::class)
        ->assertActionHidden(TestAction::make('adjustStock')->table($item))
        ->assertActionVisible(TestAction::make('stockHistory')->table($item));
});

it('never writes back an option count the group modal did not change, and sets the ones it did', function (): void {
    $tenant = Tenant::factory()->create();
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: false, max: 3)->create();
    $cheese = MenuAddOnOption::factory()->inGroup($group)->stocked(10)->create(['position' => 0]);
    $raita = MenuAddOnOption::factory()->inGroup($group)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    $page = Livewire::test(ManageMenuAddOnGroups::class)
        ->mountAction(TestAction::make('edit')->table($group));

    // Four cheese go out with orders while the modal is open.
    app(ApplyStockChanges::class)([StockChange::take(MenuAddOnOption::class, $cheese->getKey(), 4)], StockMovementReason::OrderPlaced);

    $page->setActionData(['max_picks' => 2])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($cheese->refresh()->stock_quantity)->toBe(6);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->mountAction(TestAction::make('edit')->table($group->refresh()))
        ->setActionData([
            'options.record-'.$cheese->getKey().'.stock_quantity' => 9,
            'options.record-'.$raita->getKey().'.stock_quantity' => 3,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($cheese->refresh()->stock_quantity)->toBe(9)
        ->and($raita->refresh()->stock_quantity)->toBe(3)
        ->and(array_slice(stockHistoryOf($cheese), -1))->toBe([[StockMovementReason::Count, 3, 9]])
        ->and(stockHistoryOf($raita))->toBe([[StockMovementReason::Count, 3, 3]]);
});

it('starts counting a new option from the group modal', function (): void {
    enterTenantPanel(Tenant::factory()->create(), RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Extras'],
            'max_picks' => 3,
            'options' => [[
                'name' => [Locale::English->value => 'Extra paneer'],
                'price' => '60',
                'max_per_item' => 1,
                'stock_quantity' => '15',
                'is_default' => false,
                'is_available' => true,
            ]],
        ])
        ->assertHasNoActionErrors();

    $paneer = MenuAddOnOption::query()->sole();

    expect($paneer->stock_quantity)->toBe(15)
        ->and(stockHistoryOf($paneer))->toBe([[StockMovementReason::Count, 15, 15]]);
});

it('stops offering guests an option or a combo with none left, without switching the option off', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $category = MenuCategory::factory()->inMenu($menu)->create();
    $curry = MenuItem::factory()->inCategory($category)->create();

    $extras = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: false, max: 3)->create();
    $cheese = MenuAddOnOption::factory()->inGroup($extras)->stocked(0)->create();
    $raita = MenuAddOnOption::factory()->inGroup($extras)->create();
    MenuItemAddOnGroup::factory()->linking($curry, $extras)->create();

    // A meal holding a counted item with none left.
    $meal = MenuCombo::factory()->onMenu($menu)->create();
    MenuComboItem::factory()->pairing($meal, MenuItem::factory()->inCategory($category)->stocked(0)->create())->create();

    $this->get('http://'.$tenant->slug.'.hospitality.test/menus/'.$menu->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('addOnGroups.0.options', fn (Collection $options): bool => $options->pluck('id')->all() === [$raita->getKey()])
            ->where('combos', []),
        );

    expect($cheese->refresh()->is_available)->toBeTrue();
});
