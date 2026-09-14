<?php

use App\Enums\Locale;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\MenuAddOnGroups\Pages\ManageMenuAddOnGroups;
use App\Filament\Tenant\Resources\MenuAddOnGroups\Schemas\MenuAddOnGroupForm;
use App\Filament\Tenant\Resources\MenuItems\Pages\ListMenuItems;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Add-on groups
|--------------------------------------------------------------------------
|
| A tenant's library of choices — a spice level, a bread, extras — each with
| its options and a rule for how many a guest picks, offered on as many items
| as need it. The form asks what Toast, Square and DoorDash ask: is it
| required, only one or more than one, and for more than one the minimum, the
| maximum and whether the same option may be taken twice.
|
*/

/**
 * Find an add-on group by the English half of its translated name.
 */
function addOnGroupNamed(string $name): MenuAddOnGroup
{
    return MenuAddOnGroup::query()
        ->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, $name)
        ->sole();
}

/**
 * One row of a group's options table, as an admin types it.
 *
 * A blank price is free. `upTo` is only read while the group allows quantities.
 *
 * @return array<string, mixed>
 */
function addOnOptionRow(string $name, string $price = '', int $upTo = 1, bool $default = false): array
{
    return [
        'name' => [Locale::English->value => $name],
        'price' => $price,
        'max_quantity' => $upTo,
        'is_default' => $default,
        'is_available' => true,
    ];
}

/**
 * An item on a fresh menu of the tenant given.
 */
function addOnItemFor(Tenant $tenant): MenuItem
{
    return MenuItem::factory()
        ->inCategory(MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))->create())
        ->create();
}

/**
 * How many header cells and first-row cells the options table in the open modal draws.
 *
 * Read from the rendered HTML, because the columns and a row's fields are two
 * lists that have to agree, and only the table as drawn shows whether they did.
 *
 * @return array{headers: int, cells: int}
 */
function addOnOptionsTableShape(Testable $page): array
{
    // The modal comes back as a partial of its own — keyed "action-modals" when
    // it is mounted and "action-modals.0" after an update — and the component's
    // html() holds no modal at all.
    $html = collect($page->effects['partials'] ?? [])
        ->first(fn (mixed $partial, string $key): bool => str_starts_with($key, 'action-modals'));

    expect($html)->toBeString();

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($document);
    $table = $xpath->query('//*[contains(@class, "fi-fo-table-repeater")]//table')->item(0);

    expect($table)->not->toBeNull();

    return [
        'headers' => $xpath->query('./thead/tr/th', $table)->length,
        'cells' => $xpath->query('./tbody/tr[1]/td', $table)->length,
    ];
}

it('makes a required group of only one: exactly one pick, one of each option', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Choose your bread', Locale::Tamil->value => 'ரொட்டியைத் தேர்ந்தெடுக்கவும்'],
            'requirement' => MenuAddOnGroupForm::REQUIRED,
            'selection' => MenuAddOnGroupForm::ONLY_ONE,
            'options' => [
                addOnOptionRow('Butter naan', default: true),
                addOnOptionRow('Garlic naan', '20.50'),
            ],
        ])
        ->assertHasNoActionErrors();

    $group = addOnGroupNamed('Choose your bread');
    $options = $group->options()->inMenuOrder()->get();

    expect($group->tenant_id)->toBe($tenant->getKey())
        ->and($group->min_selections)->toBe(1)
        ->and($group->max_selections)->toBe(1)
        ->and($group->allows_quantities)->toBeFalse()
        ->and($group->getTranslation('name', Locale::Tamil->value))->toBe('ரொட்டியைத் தேர்ந்தெடுக்கவும்')
        ->and($options->map(fn (MenuAddOnOption $option): string => $option->name)->all())->toBe(['Butter naan', 'Garlic naan'])
        // Left blank, a butter naan is free.
        ->and($options->pluck('price_minor_units')->all())->toBe([0, 2050])
        ->and($options->pluck('max_quantity')->all())->toBe([1, 1])
        ->and($options->pluck('is_default')->all())->toBe([true, false])
        ->and($options->pluck('tenant_id')->unique()->all())->toBe([$tenant->getKey()]);
});

it('saves an optional group of only one as at most one pick, whatever the hidden boxes still hold', function (): void {
    enterTenantPanel(Tenant::factory()->create(), RoleEnum::Owner);

    // Typed while the group was "more than one", then switched back.
    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Strength'],
            'requirement' => MenuAddOnGroupForm::OPTIONAL,
            'selection' => MenuAddOnGroupForm::ONLY_ONE,
            'min_selections' => 2,
            'max_selections' => 5,
            'allows_quantities' => true,
            'options' => [addOnOptionRow('Extra strong', '10', upTo: 3)],
        ])
        ->assertHasNoActionErrors();

    $strength = addOnGroupNamed('Strength');

    expect($strength->min_selections)->toBe(0)
        ->and($strength->max_selections)->toBe(1)
        ->and($strength->allows_quantities)->toBeFalse()
        ->and($strength->options()->sole()->max_quantity)->toBe(1);
});

it('makes an optional group of more than one, with the same option allowed twice', function (): void {
    enterTenantPanel(Tenant::factory()->create(), RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Extras'],
            'requirement' => MenuAddOnGroupForm::OPTIONAL,
            'selection' => MenuAddOnGroupForm::MORE_THAN_ONE,
            'max_selections' => 3,
            'allows_quantities' => true,
            'options' => [addOnOptionRow('Extra cheese', '40', upTo: 2), addOnOptionRow('Raita', '30')],
        ])
        ->assertHasNoActionErrors();

    $extras = addOnGroupNamed('Extras');

    expect($extras->min_selections)->toBe(0)
        ->and($extras->max_selections)->toBe(3)
        ->and($extras->allows_quantities)->toBeTrue()
        ->and($extras->options()->inMenuOrder()->pluck('max_quantity')->all())->toBe([2, 1]);
});

it('refuses a rule no guest could meet', function (array $rule, array $options, string $refused): void {
    enterTenantPanel(Tenant::factory()->create(), RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Spice level'],
            ...$rule,
            'options' => $options,
        ])
        ->assertHasActionErrors([$refused]);

    expect(MenuAddOnGroup::query()->withoutGlobalScopes()->exists())->toBeFalse();
})->with([
    'a maximum below the minimum' => [
        ['requirement' => MenuAddOnGroupForm::REQUIRED, 'selection' => MenuAddOnGroupForm::MORE_THAN_ONE, 'min_selections' => 3, 'max_selections' => 2],
        [addOnOptionRow('Mild'), addOnOptionRow('Medium'), addOnOptionRow('Hot')],
        'max_selections',
    ],
    'a minimum the options cannot add up to' => [
        ['requirement' => MenuAddOnGroupForm::REQUIRED, 'selection' => MenuAddOnGroupForm::MORE_THAN_ONE, 'min_selections' => 3, 'max_selections' => null],
        [addOnOptionRow('Mild'), addOnOptionRow('Hot')],
        'min_selections',
    ],
    'more than one with a maximum of one' => [
        ['requirement' => MenuAddOnGroupForm::OPTIONAL, 'selection' => MenuAddOnGroupForm::MORE_THAN_ONE, 'max_selections' => 1],
        [addOnOptionRow('Mild'), addOnOptionRow('Hot')],
        'max_selections',
    ],
    'two defaults where only one may be picked' => [
        ['requirement' => MenuAddOnGroupForm::OPTIONAL, 'selection' => MenuAddOnGroupForm::ONLY_ONE],
        [addOnOptionRow('Mild', default: true), addOnOptionRow('Hot', default: true)],
        // Under the options it is about, not under a hidden maximum.
        'options',
    ],
    'no options at all' => [[], [], 'options'],
]);

it('refuses more of one option than the group\'s own maximum', function (): void {
    enterTenantPanel(Tenant::factory()->create(), RoleEnum::Owner);

    // A guest who may pick two things in all cannot take three extra cheese.
    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Extras'],
            'requirement' => MenuAddOnGroupForm::OPTIONAL,
            'selection' => MenuAddOnGroupForm::MORE_THAN_ONE,
            'max_selections' => 2,
            'allows_quantities' => true,
            'options' => [addOnOptionRow('Extra cheese', '40', upTo: 3)],
        ])
        ->assertHasActionErrors(['options.*.max_quantity']);

    expect(MenuAddOnGroup::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('draws one cell per column, adding Max qty as a column and a field together', function (): void {
    $tenant = Tenant::factory()->create();
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(0, 3)->create();
    MenuAddOnOption::factory()->inGroup($group)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    $page = Livewire::test(ManageMenuAddOnGroups::class)
        ->mountAction(TestAction::make('edit')->table($group));

    // A translated name is two inputs, and a row that put both straight into
    // the table drew a cell for the hidden one, pushing every field after it
    // one column to the right.
    $before = addOnOptionsTableShape($page);

    expect($before['cells'])->toBe($before['headers']);

    // Switched on in the open modal, Max qty must arrive as a header and as a
    // cell in the same render, or the row lags a column behind its headers.
    $page->setActionData(['allows_quantities' => true]);

    $after = addOnOptionsTableShape($page);

    expect($after['cells'])->toBe($after['headers'])
        ->and($after['headers'])->toBe($before['headers'] + 1);
});

it('opens a group with its answers, and each option as stored, a free one blank', function (): void {
    $tenant = Tenant::factory()->create();
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(0, 3)->allowingQuantities()->create();
    $cheese = MenuAddOnOption::factory()->inGroup($group)->asDefault()->create(['price_minor_units' => 2050, 'max_quantity' => 2]);
    $raita = MenuAddOnOption::factory()->inGroup($group)->free()->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    $state = Livewire::test(ManageMenuAddOnGroups::class)
        ->mountAction(TestAction::make('edit')->table($group))
        ->instance()
        ->getSchema('mountedActionSchema0')
        ->getRawState();

    // Filled from the relationship the groups table already loaded with every
    // column — pins the bug where a narrower preview left these blank.
    $cheeseRow = $state['options']['record-'.$cheese->getKey()];

    expect($state['requirement'])->toBe(MenuAddOnGroupForm::OPTIONAL)
        ->and($state['selection'])->toBe(MenuAddOnGroupForm::MORE_THAN_ONE)
        ->and($cheeseRow['price'])->toBe(20.5)
        ->and($cheeseRow['max_quantity'])->toBe(2)
        ->and($cheeseRow['is_default'])->toBeTrue()
        // Free reads as the placeholder rather than "0".
        ->and($state['options']['record-'.$raita->getKey()]['price'])->toBeNull()
        // An add-on is taxed with its item, so there is no rate to fill.
        ->and($cheeseRow)->not->toHaveKey('tax_rate_percentage');
});

it('edits a group from only one to more than one, writing its options back exactly as they were stored', function (): void {
    $tenant = Tenant::factory()->create();
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(0, 1)->create();
    $cheese = MenuAddOnOption::factory()->inGroup($group)->create(['price_minor_units' => 4050, 'position' => 0]);
    $raita = MenuAddOnOption::factory()->inGroup($group)->free()->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Prices are filled in as money — a free one as blank — and saved back the
    // other way, so a save that only touched the rule must round-trip them.
    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction(TestAction::make('edit')->table($group), [
            'selection' => MenuAddOnGroupForm::MORE_THAN_ONE,
            'max_selections' => 2,
        ])
        ->assertHasNoActionErrors();

    expect($group->refresh()->max_selections)->toBe(2)
        ->and($group->min_selections)->toBe(0)
        ->and($group->options()->inMenuOrder()->pluck('id')->all())->toBe([$cheese->getKey(), $raita->getKey()])
        ->and($cheese->refresh()->price_minor_units)->toBe(4050)
        ->and($raita->refresh()->price_minor_units)->toBe(0);
});

it('attaches a group to items, after the groups each already offers', function (): void {
    $tenant = Tenant::factory()->create();
    $curry = addOnItemFor($tenant);
    $dal = MenuItem::factory()->inCategory(MenuCategory::query()->findOrFail($curry->menu_category_id))->create();

    $bread = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    $spice = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    MenuItemAddOnGroup::factory()->linking($curry, $bread)->create(['position' => 3]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction(TestAction::make('attachToItems')->table($spice), [
            'items' => [$curry->getKey(), $dal->getKey()],
        ])
        ->assertHasNoActionErrors();

    // The curry still reads its bread first.
    expect($curry->addOnGroupLinks()->inMenuOrder()->pluck('menu_add_on_group_id')->all())->toBe([$bread->getKey(), $spice->getKey()])
        ->and($dal->addOnGroupLinks()->pluck('menu_add_on_group_id')->all())->toBe([$spice->getKey()])
        ->and($spice->itemLinks()->pluck('tenant_id')->unique()->all())->toBe([$tenant->getKey()]);
});

it('attaches a group only to this tenant\'s items that do not offer it yet', function (): void {
    $tenant = Tenant::factory()->create();
    $already = addOnItemFor($tenant);
    $theirItem = addOnItemFor(Tenant::factory()->create());

    $spice = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    MenuItemAddOnGroup::factory()->linking($already, $spice)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    foreach ([$already, $theirItem] as $item) {
        Livewire::test(ManageMenuAddOnGroups::class)
            ->callAction(TestAction::make('attachToItems')->table($spice), ['items' => [$item->getKey()]]);
    }

    expect($spice->itemLinks()->count())->toBe(1)
        ->and(MenuItemAddOnGroup::query()->where('menu_item_id', $theirItem->getKey())->exists())->toBeFalse();
});

it('takes a deleted group off every item, and leaves the items', function (): void {
    $tenant = Tenant::factory()->create();
    $item = addOnItemFor($tenant);
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    $option = MenuAddOnOption::factory()->inGroup($group)->create();
    $link = MenuItemAddOnGroup::factory()->linking($item, $group)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction(TestAction::make('delete')->table($group));

    expect(MenuAddOnGroup::query()->withoutGlobalScopes()->whereKey($group->getKey())->exists())->toBeFalse()
        ->and(MenuAddOnOption::query()->whereKey($option->getKey())->exists())->toBeFalse()
        ->and(MenuItemAddOnGroup::query()->whereKey($link->getKey())->exists())->toBeFalse()
        ->and(MenuItem::query()->withoutGlobalScopes()->whereKey($item->getKey())->exists())->toBeTrue();
});

it('shows staff the groups and lets them change nothing', function (): void {
    $tenant = Tenant::factory()->create();
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->create();

    enterTenantPanel($tenant, RoleEnum::Staff);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$group])
        ->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('edit')->table($group))
        ->assertActionHidden(TestAction::make('attachToItems')->table($group))
        ->assertActionHidden(TestAction::make('delete')->table($group));
});

it('lists only this tenant\'s groups', function (): void {
    $tenant = Tenant::factory()->create();
    $mine = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    $theirs = MenuAddOnGroup::factory()->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('refuses an option or a link reaching into another tenant\'s group, even around the form', function (): void {
    $tenant = Tenant::factory()->create();
    $theirGroup = MenuAddOnGroup::factory()->create();
    $myItem = addOnItemFor($tenant);

    expect(fn () => MenuAddOnOption::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'menu_add_on_group_id' => $theirGroup->getKey(),
    ]))->toThrow(LogicException::class, 'another tenant')
        ->and(fn () => MenuItemAddOnGroup::factory()->linking($myItem, $theirGroup)->create())
        ->toThrow(LogicException::class, 'another tenant');
});

it('lists groups in the same number of queries however many there are', function (): void {
    $tenant = Tenant::factory()->create();
    $category = MenuCategory::factory()->inMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))->create();

    $addGroup = function () use ($tenant, $category): void {
        $group = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
        MenuAddOnOption::factory()->count(2)->inGroup($group)->create();
        MenuItemAddOnGroup::factory()->linking(MenuItem::factory()->inCategory($category)->create(), $group)->create();
    };

    $queriesToRender = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(ManageMenuAddOnGroups::class)->assertOk();

        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $addGroup();

    enterTenantPanel($tenant, RoleEnum::Owner);

    // The first render warms what a request caches, the permissions among them.
    $queriesToRender();
    $one = $queriesToRender();

    $addGroup();
    $addGroup();
    $addGroup();

    expect($queriesToRender())->toBe($one);
});

it('makes a group from an item\'s form without leaving the item', function (): void {
    $tenant = Tenant::factory()->create();
    $item = addOnItemFor($tenant);
    $link = MenuItemAddOnGroup::factory()
        ->linking($item, MenuAddOnGroup::factory()->ofTenant($tenant)->create())
        ->create();
    $row = 'addOnGroupLinks.record-'.$link->getKey().'.menu_add_on_group_id';

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Mounted, filled and then called rather than called in one go: calling
    // first asks whether the select's action is visible, before the edit form
    // holding the select's row has been filled.
    $page = Livewire::test(ListMenuItems::class)
        ->mountAction([
            TestAction::make('edit')->table($item),
            TestAction::make('createOption')->schemaComponent($row),
        ])
        ->setActionData([
            'name' => [Locale::English->value => 'Spice level'],
            'requirement' => MenuAddOnGroupForm::REQUIRED,
            'selection' => MenuAddOnGroupForm::ONLY_ONE,
            'options' => [addOnOptionRow('Mild'), addOnOptionRow('Hot')],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $spice = addOnGroupNamed('Spice level');

    // Made outside its own page, so the tenant is stamped by hand, and the
    // options are saved by the repeater nested in the select's modal.
    expect($spice->tenant_id)->toBe($tenant->getKey())
        ->and($spice->min_selections)->toBe(1)
        ->and($spice->max_selections)->toBe(1)
        ->and($spice->options()->pluck('tenant_id')->all())->toBe([$tenant->getKey(), $tenant->getKey()]);

    // And the row the select was opened from now names it.
    $page->assertActionDataSet([$row => $spice->getKey()]);
});
