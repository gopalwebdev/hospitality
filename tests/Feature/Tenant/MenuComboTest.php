<?php

use App\Enums\ItemAvailability;
use App\Enums\Locale;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\Menus\Pages\ArrangeMenu;
use App\Filament\Tenant\Resources\Menus\RelationManagers\CombosRelationManager;
use App\Filament\Tenant\Resources\Menus\Schemas\MenuComboForm;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuComboItem;
use App\Models\MenuItem;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
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
 * Open the table a menu's combos are added, edited and put in order in — what its Combos row opens on the menu page.
 */
function combosOf(Menu $menu): Testable
{
    return Livewire::test(CombosRelationManager::class, ['ownerRecord' => $menu, 'pageClass' => ArrangeMenu::class]);
}

/**
 * An item on the given menu, in a category of its own.
 */
function itemOn(Menu $menu): MenuItem
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
| items. Its price is its own, never derived from what is inside it.
|
*/

it('creates a combo on the menu with the items it contains', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $burger = itemOn($menu);
    $fries = itemOn($menu);
    $existing = MenuCombo::factory()->onMenu($menu)->create(['position' => 3]);

    enterTenantPanel($tenant, RoleEnum::Owner);

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

    expect($combo->tenant_id)->toBe($tenant->getKey())
        ->and($combo->menu_id)->toBe($menu->getKey())
        // At the end of the combos, where whoever added it looks for it.
        ->and($combo->position)->toBeGreaterThan($existing->position)
        // ₹299.00 is 29900 paise, exactly. No float reaches the column.
        ->and($combo->price_minor_units)->toBe(29900)
        ->and($combo->compare_at_price_minor_units)->toBe(36000)
        ->and($combo->getTranslation('name', Locale::Tamil->value))->toBe('பர்கர் உணவு')
        ->and($combo->comboItems()->count())->toBe(2)
        ->and($combo->comboItems()->where('menu_item_id', $fries->getKey())->value('quantity'))->toBe(2);
});

it('leaves the compare-at price empty rather than storing a zero', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);

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

it('keeps how many of a combo one order may hold', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    combosOf($menu)
        ->callAction(TestAction::make('create')->table(), [
            'name' => [Locale::English->value => 'Party Platter'],
            'price' => '1200',
            'availability' => ItemAvailability::Available->value,
            'max_quantity' => '1',
        ])
        ->assertHasNoActionErrors();

    expect(comboNamed('Party Platter'))
        ->min_quantity->toBe(1)
        ->max_quantity->toBe(1);
});

it('refuses a compare-at price that is not above what is charged', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);

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
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    MenuCombo::factory()->onMenu($menu)->create(['name' => [Locale::English->value => 'Family Feast']]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    combosOf($menu)
        ->callAction(TestAction::make('create')->table(), [
            'name' => [Locale::English->value => 'Family Feast'],
            'price' => '999',
            'availability' => ItemAvailability::Available->value,
        ])
        ->assertHasActionErrors(['name.'.Locale::English->value]);
});

it('offers only this menu\'s items as combo contents', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $otherMenu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $mine = itemOn($menu);
    $pillow = MenuItem::factory()->inCategory(MenuCategory::factory()->inMenu($menu)->create())->service()->create();
    $elsewhere = itemOn($otherMenu);

    $theirs = Tenant::factory()->create();
    $theirItem = itemOn(Menu::factory()->create(['tenant_id' => $theirs->getKey()]));

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The same call the select inside the repeater makes. A combo may only
    // contain items from the menu it is offered on — and items, never a
    // service request, which is asked for rather than sold in a bundle.
    $groups = MenuComboForm::itemOptions($menu->getKey());
    $offered = collect($groups)->map(fn (array $options): array => array_keys($options))->flatten()->all();

    expect($offered)->toContain($mine->getKey())
        ->and($offered)->not->toContain($pillow->getKey())
        ->and($offered)->not->toContain($elsewhere->getKey())
        ->and($offered)->not->toContain($theirItem->getKey())
        // Each option is the item's name alone, under its category's heading.
        ->and($groups[$mine->load('menuCategory.parent')->menuCategory->path()][$mine->getKey()])->toBe($mine->name);
});

it('refuses a service request as combo contents', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $pillow = MenuItem::factory()->inCategory(MenuCategory::factory()->inMenu($menu)->create())->service()->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The picker does not offer it, and the select's own validation refuses
    // the id when it is sent anyway.
    combosOf($menu)
        ->callAction(TestAction::make('create')->table(), [
            'name' => [Locale::English->value => 'Pillow Deal'],
            'price' => '99',
            'availability' => ItemAvailability::Available->value,
            'comboItems' => [
                ['menu_item_id' => $pillow->getKey(), 'quantity' => 1],
            ],
        ])
        ->assertHasActionErrors();

    expect(MenuCombo::query()->withoutGlobalScopes()->where('name->'.Locale::English->value, 'Pillow Deal')->exists())->toBeFalse();
});

it('refuses a combo containing another tenant\'s item, even around the form', function (): void {
    $mine = Tenant::factory()->create();
    $combo = MenuCombo::factory()->onMenu(Menu::factory()->create(['tenant_id' => $mine->getKey()]))->create();

    $theirs = Tenant::factory()->create();
    $theirItem = itemOn(Menu::factory()->create(['tenant_id' => $theirs->getKey()]));

    expect(fn () => MenuComboItem::factory()->pairing($combo, $theirItem)->create())
        ->toThrow(LogicException::class, 'another tenant');
});

it('refuses the same item twice in one combo', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $menuItem = itemOn($menu);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // An item appears once, with a quantity — two rows would show as a
    // duplicate line to the guest. No unique index stands behind the form.
    combosOf($menu)
        ->callAction(TestAction::make('create')->table(), [
            'name' => [Locale::English->value => 'Double Trouble'],
            'price' => '199',
            'availability' => ItemAvailability::Available->value,
            'comboItems' => [
                ['menu_item_id' => $menuItem->getKey(), 'quantity' => 1],
                ['menu_item_id' => $menuItem->getKey(), 'quantity' => 1],
            ],
        ])
        ->assertHasActionErrors();

    expect(MenuCombo::query()->withoutGlobalScopes()->where('name->'.Locale::English->value, 'Double Trouble')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Pricing
|--------------------------------------------------------------------------
*/

it('prices a combo on its own rather than from its contents', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $combo = MenuCombo::factory()->onMenu($menu)->create(['price_minor_units' => 29900]);

    $burger = itemOn($menu);
    $burger->update(['price_minor_units' => 18000]);
    $fries = itemOn($menu);
    $fries->update(['price_minor_units' => 9000]);

    MenuComboItem::factory()->pairing($combo, $burger)->create();
    MenuComboItem::factory()->pairing($combo, $fries)->quantity(2)->create();

    $combo->load('comboItems.menuItem');

    // The whole point of a combo is that it costs less than its parts, so the
    // contents total is only ever shown beside the price, never used as it.
    expect($combo->contentsPriceMinorUnits())->toBe(36000)
        ->and($combo->price_minor_units)->toBe(29900);
});

it('falls back to the tenant\'s GST rate, and overrides it when told', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings->update(['tax_rate_basis_points' => 1800]);
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

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
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $first = MenuCombo::factory()->onMenu($menu)->create(['position' => 0]);
    $second = MenuCombo::factory()->onMenu($menu)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    combosOf($menu)->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($second->refresh()->position)->toBeLessThan($first->refresh()->position);
});

it('keeps changing and rearranging combos away from someone who may only read the menu', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $first = MenuCombo::factory()->onMenu($menu)->create(['position' => 0]);
    $second = MenuCombo::factory()->onMenu($menu)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Staff);

    // Reading the combos is menu.view, so the table opens; changing them is
    // menu.manage. reorderTable() short-circuits on the reorder() policy, so
    // the drag is asserted by calling it rather than by a hidden button.
    combosOf($menu)
        ->assertOk()
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($first))
        ->assertActionHidden(TestAction::make('delete')->table($first))
        ->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($first->refresh()->position)->toBe(0)
        ->and($second->refresh()->position)->toBe(1);
});

it('shows only this menu\'s combos', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $otherMenu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $mine = MenuCombo::factory()->onMenu($menu)->create();
    $elsewhere = MenuCombo::factory()->onMenu($otherMenu)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    combosOf($menu)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$elsewhere]);
});

it('edits a combo from its table, keeping what is in it', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $combo = MenuCombo::factory()->onMenu($menu)->create(['price_minor_units' => 29900]);
    $burger = itemOn($menu);

    $line = MenuComboItem::factory()->pairing($combo, $burger)->quantity(2)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The contents are a repeater bound to a relationship. Saving a new price
    // must write the combo and leave the burger it already holds alone.
    combosOf($menu)
        ->callAction(TestAction::make('edit')->table($combo), [
            'price' => '249',
        ])
        ->assertHasNoActionErrors();

    expect($combo->refresh()->price_minor_units)->toBe(24900)
        ->and($combo->comboItems()->pluck('id')->all())->toBe([$line->getKey()])
        ->and($line->refresh()->quantity)->toBe(2);
});

it('deletes a combo from its table, leaving its items alone', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $combo = MenuCombo::factory()->onMenu($menu)->create();
    $menuItem = itemOn($menu);

    MenuComboItem::factory()->pairing($combo, $menuItem)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    combosOf($menu)->callAction(TestAction::make('delete')->table($combo));

    expect(MenuCombo::query()->withoutGlobalScopes()->find($combo->getKey()))->toBeNull()
        ->and(MenuItem::query()->withoutGlobalScopes()->find($menuItem->getKey()))->not->toBeNull();
});

it('leaves the items alone when a combo is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $combo = MenuCombo::factory()->onMenu($menu)->create();
    $menuItem = itemOn($menu);

    MenuComboItem::factory()->pairing($combo, $menuItem)->create();

    $combo->delete();

    expect(MenuItem::query()->withoutGlobalScopes()->find($menuItem->getKey()))->not->toBeNull()
        ->and(MenuComboItem::query()->count())->toBe(0);
});

it('takes an item out of every combo when it is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $combo = MenuCombo::factory()->onMenu($menu)->create();
    $menuItem = itemOn($menu);

    MenuComboItem::factory()->pairing($combo, $menuItem)->create();

    $menuItem->delete();

    // A combo still advertising an item that no longer exists is worse than one
    // that is a line shorter.
    expect(MenuComboItem::query()->count())->toBe(0)
        ->and(MenuCombo::query()->withoutGlobalScopes()->find($combo->getKey()))->not->toBeNull();
});

it('takes a menu\'s combos down with it', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $combo = MenuCombo::factory()->onMenu($menu)->create();

    $menu->delete();

    expect(MenuCombo::query()->withoutGlobalScopes()->find($combo->getKey()))->toBeNull();
});

it('keeps an unavailable combo off the guest\'s menu', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $offered = MenuCombo::factory()->onMenu($menu)->create();
    $soldOut = MenuCombo::factory()->onMenu($menu)->unavailable()->create();

    $orderable = MenuCombo::query()->orderable()->pluck('id')->all();

    expect($orderable)->toContain($offered->getKey())
        ->not->toContain($soldOut->getKey());
});

it('takes a hidden menu\'s combos down with it', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->hidden()->create(['tenant_id' => $tenant->getKey()]);
    MenuCombo::factory()->onMenu($menu)->create();

    expect(MenuCombo::query()->orderable()->count())->toBe(0);
});
