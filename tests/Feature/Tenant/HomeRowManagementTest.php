<?php

use App\Enums\HomeRowLayout;
use App\Enums\HomeTileAction;
use App\Enums\Locale;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\HomeRows\Pages\EditHomeRow;
use App\Filament\Tenant\Resources\HomeRows\Pages\ListHomeRows;
use App\Filament\Tenant\Resources\HomeRows\RelationManagers\TilesRelationManager;
use App\Models\HomeRow;
use App\Models\HomeTile;
use App\Models\Menu;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Who may arrange the rows
|--------------------------------------------------------------------------
|
| The same pair of permissions the tiles inside them answer to: storefront.view
| to look, storefront.manage to change anything.
|
*/

it('lets a tenant admin arrange the rows', function (): void {
    $tenant = Tenant::factory()->create();
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    $admin = enterTenantPanel($tenant, RoleEnum::Admin);

    expect($admin->can('viewAny', HomeRow::class))->toBeTrue()
        ->and($admin->can('create', HomeRow::class))->toBeTrue()
        ->and($admin->can('update', $row))->toBeTrue()
        ->and($admin->can('reorder', HomeRow::class))->toBeTrue();
});

it('lets staff see the rows but not rearrange them', function (): void {
    $tenant = Tenant::factory()->create();
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    $staff = enterTenantPanel($tenant, RoleEnum::Staff);

    expect($staff->can(PermissionEnum::StorefrontView->value))->toBeTrue()
        ->and($staff->can('viewAny', HomeRow::class))->toBeTrue()
        ->and($staff->can('create', HomeRow::class))->toBeFalse()
        ->and($staff->can('update', $row))->toBeFalse();
});

it('keeps someone with no role off the home screen pages', function (): void {
    $tenant = Tenant::factory()->create();
    $nobody = User::factory()->ofTenant($tenant)->create();

    expect($nobody->can('viewAny', HomeRow::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Making a row
|--------------------------------------------------------------------------
*/

it('creates a row against the tenant whose panel it is', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(ListHomeRows::class)
        ->callAction('create', [
            'title' => [Locale::English->value => 'This month', Locale::Tamil->value => 'இந்த மாதம்'],
            'layout' => HomeRowLayout::Carousel->value,
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $row = HomeRow::query()->withoutGlobalScopes()->sole();

    expect($row->tenant_id)->toBe($tenant->getKey())
        ->and($row->layout)->toBe(HomeRowLayout::Carousel)
        ->and($row->title)->toBe('This month')
        ->and($row->getTranslation('title', Locale::Tamil->value))->toBe('இந்த மாதம்');
});

it('lets a row be created with no heading at all', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Admin);

    // A banner into the menu speaks for itself, so the heading is optional in
    // every language — unlike a menu's name, which the fallback requires.
    Livewire::test(ListHomeRows::class)
        ->callAction('create', [
            'layout' => HomeRowLayout::Banner->value,
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    expect(HomeRow::query()->withoutGlobalScopes()->sole()->title)->toBeEmpty();
});

it('shows only this tenant\'s rows', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    $myRow = HomeRow::factory()->ofTenant($mine)->create();
    $theirRow = HomeRow::factory()->ofTenant($theirs)->create();

    enterTenantPanel($mine, RoleEnum::Admin);

    Livewire::test(ListHomeRows::class)
        ->assertCanSeeTableRecords([$myRow])
        ->assertCanNotSeeTableRecords([$theirRow]);
});

it('orders the rows the way the home screen shows them', function (): void {
    $tenant = Tenant::factory()->create();

    $last = HomeRow::factory()->ofTenant($tenant)->create(['position' => 5]);
    $first = HomeRow::factory()->ofTenant($tenant)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Admin);

    Livewire::test(ListHomeRows::class)
        ->assertCanSeeTableRecords([$first, $last], inOrder: true);
});

it('takes a row\'s tiles with it when it is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $row = HomeRow::factory()->ofTenant($tenant)->create();
    $tile = HomeTile::factory()->openingMenu($menu)->inRow($row)->create();

    $row->delete();

    expect(HomeTile::query()->withoutGlobalScopes()->whereKey($tile->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Tenant isolation
|--------------------------------------------------------------------------
*/

it('refuses a tile in another tenant\'s row, even around the form', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();
    $theirRow = HomeRow::factory()->ofTenant($theirs)->create();

    // HomeTileObserver is what makes this an error rather than something a
    // forgotten where() lets through.
    expect(fn () => HomeTile::factory()->create([
        'tenant_id' => $mine->getKey(),
        'home_row_id' => $theirRow->getKey(),
    ]))->toThrow(LogicException::class, 'another tenant');
});

/*
|--------------------------------------------------------------------------
| Link tiles
|--------------------------------------------------------------------------
*/

it('creates a tile that leaves the app for a link', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Admin);
    $row = HomeRow::factory()->ofTenant($tenant)->layout(HomeRowLayout::Links)->create();

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::English->value => 'Instagram'],
            'action' => HomeTileAction::Link->value,
            'url' => 'https://instagram.com/spicegarden',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $tile = HomeTile::query()->withoutGlobalScopes()
        ->where('label->'.Locale::English->value, 'Instagram')
        ->sole();

    expect($tile->action)->toBe(HomeTileAction::Link)
        ->and($tile->url)->toBe('https://instagram.com/spicegarden')
        ->and($tile->menu_id)->toBeNull()
        ->and($tile->document_path)->toBeNull();
});

it('refuses a link tile with no address', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Admin);
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::English->value => 'Nowhere'],
            'action' => HomeTileAction::Link->value,
            'is_active' => true,
        ])
        ->assertHasActionErrors(['url']);
});

it('clears a link when a tile stops being one', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $row = HomeRow::factory()->ofTenant($tenant)->create();
    $tile = HomeTile::factory()->linkingTo('https://example.com')->inRow($row)->create();

    $tile->update(['action' => HomeTileAction::Menu, 'menu_id' => $menu->getKey()]);

    expect($tile->refresh()->url)->toBeNull()
        ->and($tile->menu_id)->toBe($menu->getKey());
});
