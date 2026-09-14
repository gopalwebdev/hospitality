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
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Group;
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
| its options and a rule for how many a guest picks, offered on as many items
| as need it.
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
 * @return array<string, mixed>
 */
function addOnOptionRow(string $name, string $price = '0', int $upTo = 1, bool $preselected = false): array
{
    return [
        'name' => [Locale::English->value => $name],
        'price' => $price,
        'max_quantity' => $upTo,
        'is_preselected' => $preselected,
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

it('makes a group with its options, typed as money and a percentage and stored as integers', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Choose your bread', Locale::Tamil->value => 'ரொட்டியைத் தேர்ந்தெடுக்கவும்'],
            'is_required' => true,
            'min_selections' => 1,
            'max_selections' => 1,
            'options' => [
                addOnOptionRow('Butter naan', preselected: true),
                [...addOnOptionRow('Garlic naan', '20.50'), 'tax_rate_percentage' => '18'],
            ],
        ])
        ->assertHasNoActionErrors();

    $group = addOnGroupNamed('Choose your bread');
    $options = $group->options()->inMenuOrder()->get();

    expect($group->tenant_id)->toBe($tenant->getKey())
        ->and($group->isRequired())->toBeTrue()
        ->and($group->max_selections)->toBe(1)
        ->and($group->getTranslation('name', Locale::Tamil->value))->toBe('ரொட்டியைத் தேர்ந்தெடுக்கவும்')
        ->and($options->map(fn (MenuAddOnOption $option): string => $option->name)->all())->toBe(['Butter naan', 'Garlic naan'])
        // Zero is a real price: a butter naan costs nothing extra.
        ->and($options->pluck('price_minor_units')->all())->toBe([0, 2050])
        ->and($options->pluck('tax_rate_basis_points')->all())->toBe([null, 1800])
        ->and($options->pluck('is_preselected')->all())->toBe([true, false])
        ->and($options->pluck('tenant_id')->unique()->all())->toBe([$tenant->getKey()]);
});

it('makes a group optional by leaving "Guest must choose" off', function (): void {
    enterTenantPanel(Tenant::factory()->create(), RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Extras'],
            'max_selections' => 3,
            'options' => [addOnOptionRow('Extra cheese', '40', upTo: 2)],
        ])
        ->assertHasNoActionErrors();

    $extras = addOnGroupNamed('Extras');

    expect($extras->min_selections)->toBe(0)
        ->and($extras->max_selections)->toBe(3);
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
    'at most fewer than at least' => [
        ['is_required' => true, 'min_selections' => 2, 'max_selections' => 1],
        [addOnOptionRow('Mild', upTo: 2), addOnOptionRow('Hot')],
        'max_selections',
    ],
    'at least more than the options add up to' => [
        ['is_required' => true, 'min_selections' => 3],
        [addOnOptionRow('Mild'), addOnOptionRow('Hot')],
        'min_selections',
    ],
    'more options set as the default than a guest may pick' => [
        ['max_selections' => 1],
        [addOnOptionRow('Mild', preselected: true), addOnOptionRow('Hot', preselected: true)],
        // Attached to the options repeater rather than to Max choices, so the
        // message reads under the options it is actually about.
        'options',
    ],
    'no options at all' => [[], [], 'options'],
]);

it('refuses an option allowed more than the group\'s own maximum', function (): void {
    enterTenantPanel(Tenant::factory()->create(), RoleEnum::Owner);

    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Choose your bread'],
            'is_required' => true,
            'min_selections' => 1,
            'max_selections' => 1,
            // A guest who may choose 1 in total cannot walk away with 3 garlic naan.
            'options' => [addOnOptionRow('Butter naan'), addOnOptionRow('Garlic naan', '20', upTo: 3)],
        ])
        ->assertHasActionErrors(['options.*.max_quantity']);

    expect(MenuAddOnGroup::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('gives the options table exactly one cell per column, a translated name included', function (): void {
    $tenant = Tenant::factory()->create();
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    MenuAddOnOption::factory()->inGroup($group)->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    $schema = Livewire::test(ManageMenuAddOnGroups::class)
        ->mountAction(TestAction::make('edit')->table($group))
        ->instance()
        ->getSchema('mountedActionSchema0');

    $repeater = $schema->getComponent(fn (mixed $component): bool => $component instanceof Repeater, withHidden: true);
    $row = array_first($repeater->getChildSchemas());
    $cells = $row->getComponents(withHidden: true);

    // A row that put a translated name's two inputs straight into the row —
    // rather than wrapped as one Group — pushed every field after them one
    // column to the right, and dropped Available off the end entirely.
    expect($cells)->toHaveCount(count($repeater->getTableColumns()))
        ->and($cells[0])->toBeInstanceOf(Group::class)
        ->and($cells[0]->getChildSchema()->getComponents(withHidden: true))->toHaveCount(2);
});

it('shows each option\'s stored price, quantity and rate when the group is opened to edit', function (): void {
    $tenant = Tenant::factory()->create();
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->create();
    $naan = MenuAddOnOption::factory()->inGroup($group)->taxedAt(1800)->create([
        'price_minor_units' => 2050,
        'max_quantity' => 3,
    ]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    $state = Livewire::test(ManageMenuAddOnGroups::class)
        ->mountAction(TestAction::make('edit')->table($group))
        ->instance()
        ->getSchema('mountedActionSchema0')
        ->getRawState();

    // Pins the bug where the groups table's preview loaded `options` with too
    // few columns, and the edit form filled from that instead of asking again.
    $row = $state['options']['record-'.$naan->getKey()];

    expect($row['price'])->toBe(20.5)
        ->and($row['max_quantity'])->toBe(3)
        ->and($row['tax_rate_percentage'])->toBe(18.0);
});

it('edits a group\'s rule, writing its options back exactly as they were stored', function (): void {
    $tenant = Tenant::factory()->create();
    $group = MenuAddOnGroup::factory()->ofTenant($tenant)->choosing(0, 1)->create();
    $cheese = MenuAddOnOption::factory()->inGroup($group)->taxedAt(1800)->create(['price_minor_units' => 4050, 'position' => 0]);
    $raita = MenuAddOnOption::factory()->inGroup($group)->create(['price_minor_units' => 3000, 'position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // Options are filled in as money and a percentage and saved back the other
    // way, so a save that only touched the rule must round-trip both.
    Livewire::test(ManageMenuAddOnGroups::class)
        ->callAction(TestAction::make('edit')->table($group), ['max_selections' => 2])
        ->assertHasNoActionErrors();

    expect($group->refresh()->max_selections)->toBe(2)
        ->and($group->min_selections)->toBe(0)
        ->and($group->options()->inMenuOrder()->pluck('id')->all())->toBe([$cheese->getKey(), $raita->getKey()])
        ->and($cheese->refresh()->price_minor_units)->toBe(4050)
        ->and($cheese->tax_rate_basis_points)->toBe(1800)
        ->and($raita->refresh()->price_minor_units)->toBe(3000);
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
            'min_selections' => 1,
            'max_selections' => 1,
            'options' => [addOnOptionRow('Mild'), addOnOptionRow('Hot')],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $spice = addOnGroupNamed('Spice level');

    // Made outside its own page, so the tenant is stamped by hand, and the
    // options are saved by the repeater nested in the select's modal.
    expect($spice->tenant_id)->toBe($tenant->getKey())
        ->and($spice->options()->pluck('tenant_id')->all())->toBe([$tenant->getKey(), $tenant->getKey()]);

    // And the row the select was opened from now names it.
    $page->assertActionDataSet([$row => $spice->getKey()]);
});
