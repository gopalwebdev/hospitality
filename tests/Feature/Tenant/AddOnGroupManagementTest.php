<?php

use App\Enums\Locale;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\MenuAddOnGroups\Pages\ManageMenuAddOnGroups;
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
| its options and a rule for what a guest picks, offered on as many items as
| need it. The form asks three things: is it required, the most a guest may
| pick, and whether the same option may be taken twice.
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
 * A blank price is free. `upTo` is disabled, and saved as one, while the group
 * is a single pick.
 *
 * @return array<string, mixed>
 */
function addOnOptionRow(string $name, string $price = '', int $upTo = 1, bool $default = false): array
{
    return [
        'name' => [Locale::English->value => $name],
        'price' => $price,
        'max_per_item' => $upTo,
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

it('makes a required group of one pick, one of each option', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Choose your bread', Locale::Tamil->value => 'ரொட்டியைத் தேர்ந்தெடுக்கவும்'],
            'is_required' => true,
            'max_picks' => 1,
            'options' => [
                addOnOptionRow('Butter naan', default: true),
                addOnOptionRow('Garlic naan', '20.50'),
            ],
        ])
        ->assertHasNoActionErrors();

    $group = addOnGroupNamed('Choose your bread');
    $options = $group->options()->inMenuOrder()->get();

    expect($group->tenant_id)->toBe($tenant->getKey())
        ->and($group->is_required)->toBeTrue()
        ->and($group->max_picks)->toBe(1)
        ->and($group->getTranslation('name', Locale::Tamil->value))->toBe('ரொட்டியைத் தேர்ந்தெடுக்கவும்')
        ->and($options->map(fn (MenuAddOnOption $option): string => $option->name)->all())->toBe(['Butter naan', 'Garlic naan'])
        // Left blank, a butter naan is free.
        ->and($options->pluck('price')->all())->toBe([0, 2050])
        ->and($options->pluck('max_per_item')->all())->toBe([1, 1])
        ->and($options->pluck('is_default')->all())->toBe([true, false])
        ->and($options->pluck('tenant_id')->unique()->all())->toBe([$tenant->getKey()]);
});

it('makes an optional group with no limit, the same option allowed twice', function (): void {
    enterTenantPanel(Tenant::factory()->create(), RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Extras'],
            'is_required' => false,
            'max_picks' => '',
            'options' => [addOnOptionRow('Extra cheese', '40', upTo: 2), addOnOptionRow('Raita', '30')],
        ])
        ->assertHasNoActionErrors();

    $extras = addOnGroupNamed('Extras');

    // Blank is any number of picks, not a limit of none.
    expect($extras->is_required)->toBeFalse()
        ->and($extras->max_picks)->toBeNull()
        ->and($extras->options()->inMenuOrder()->pluck('max_per_item')->all())->toBe([2, 1]);
});

it('forces every option to one while a guest may pick only one, whatever Max each was typed', function (): void {
    enterTenantPanel(Tenant::factory()->create(), RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Strength'],
            'max_picks' => 1,
            'options' => [addOnOptionRow('Extra strong', '10', upTo: 3)],
        ])
        ->assertHasNoActionErrors();

    $strength = addOnGroupNamed('Strength');

    expect($strength->max_picks)->toBe(1)
        ->and($strength->options()->sole()->max_per_item)->toBe(1);
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
    'a maximum of none' => [
        ['max_picks' => 0],
        [addOnOptionRow('Mild'), addOnOptionRow('Hot')],
        'max_picks',
    ],
    'two defaults where only one may be picked' => [
        ['max_picks' => 1],
        [addOnOptionRow('Mild', default: true), addOnOptionRow('Hot', default: true)],
        // Under the options it is about, not under the maximum.
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
            'max_picks' => 2,
            'options' => [addOnOptionRow('Extra cheese', '40', upTo: 3)],
        ])
        ->assertHasActionErrors(['options.*.max_per_item']);

    expect(MenuAddOnGroup::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('opens a group with its answers, and each option as stored, a free one blank', function (): void {
    $tenant = Tenant::factory()->create();
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: false, max: 3)->create();
    $cheese = MenuAddOnOption::factory()->inGroup($group)->asDefault()->create(['price' => 2050, 'max_per_item' => 2]);
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

    expect($state['is_required'])->toBeFalse()
        ->and($state['max_picks'])->toBe(3)
        ->and($cheeseRow['price'])->toBe(20.5)
        ->and($cheeseRow['max_per_item'])->toBe(2)
        ->and($cheeseRow['is_default'])->toBeTrue()
        // Free reads as the placeholder rather than "0".
        ->and($state['options']['record-'.$raita->getKey()]['price'])->toBeNull()
        // An add-on is normally taxed with its item, so the rate is offered and
        // left blank — the placeholder reads "Item's". A rate here is for an
        // option that is really a separate supply.
        ->and($cheeseRow['tax_rate_percentage'])->toBeNull()
        ->and($cheeseRow['hsn_sac_code'])->toBeNull();
});

it('edits a group from one pick to two, writing its options back exactly as they were stored', function (): void {
    $tenant = Tenant::factory()->create();
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(required: false, max: 1)->create();
    $cheese = MenuAddOnOption::factory()->inGroup($group)->create(['price' => 4050, 'position' => 0]);
    $raita = MenuAddOnOption::factory()->inGroup($group)->free()->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Prices are filled in as money — a free one as blank — and saved back the
    // other way, so a save that only touched the rule must round-trip them.
    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction(TestAction::make('edit')->table($group), [
            'max_picks' => 2,
        ])
        ->assertHasNoActionErrors();

    expect($group->refresh()->max_picks)->toBe(2)
        ->and($group->is_required)->toBeFalse()
        ->and($group->options()->inMenuOrder()->pluck('id')->all())->toBe([$cheese->getKey(), $raita->getKey()])
        ->and($cheese->refresh()->price)->toBe(4050)
        ->and($raita->refresh()->price)->toBe(0);
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
            'is_required' => true,
            'max_picks' => 1,
            'options' => [addOnOptionRow('Mild'), addOnOptionRow('Hot')],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $spice = addOnGroupNamed('Spice level');

    // Made outside its own page, so the tenant is stamped by hand, and the
    // options are saved by the repeater nested in the select's modal.
    expect($spice->tenant_id)->toBe($tenant->getKey())
        ->and($spice->is_required)->toBeTrue()
        ->and($spice->max_picks)->toBe(1)
        ->and($spice->options()->pluck('tenant_id')->all())->toBe([$tenant->getKey(), $tenant->getKey()]);

    // And the row the select was opened from now names it.
    $page->assertActionDataSet([$row => $spice->getKey()]);
});
