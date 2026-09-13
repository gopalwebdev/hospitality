<?php

use App\Enums\HomeTileAction;
use App\Enums\Locale;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\HomeRows\Pages\EditHomeRow;
use App\Filament\Tenant\Resources\HomeRows\RelationManagers\TilesRelationManager;
use App\Models\HomeRow;
use App\Models\HomeTile;
use App\Models\Menu;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Find a tile by the English half of its translated label.
 */
function tileLabelled(string $label): HomeTile
{
    return HomeTile::query()
        ->withoutGlobalScopes()
        ->where('label->'.Locale::English->value, $label)
        ->sole();
}

/*
|--------------------------------------------------------------------------
| Who may arrange the home screen
|--------------------------------------------------------------------------
|
| Its own pair of permissions rather than the menu's: the home screen is the
| shop window, and a tenant may want someone who can rearrange it without
| also being able to rewrite prices.
|
*/

it('lets a tenant owner arrange the home screen', function (): void {
    $tenant = Tenant::factory()->create();
    $tile = HomeTile::factory()
        ->openingMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();

    $owner = enterTenantPanel($tenant, RoleEnum::Owner);

    expect($owner->can('viewAny', HomeTile::class))->toBeTrue()
        ->and($owner->can('create', HomeTile::class))->toBeTrue()
        ->and($owner->can('update', $tile))->toBeTrue()
        ->and($owner->can('delete', $tile))->toBeTrue()
        // Dragging the rows is the point of the page, and strictAuthorization
        // makes a missing reorder() a 500 rather than a refusal.
        ->and($owner->can('reorder', HomeTile::class))->toBeTrue();
});

it('lets staff see the home screen but not rearrange it', function (): void {
    $tenant = Tenant::factory()->create();
    $tile = HomeTile::factory()
        ->openingMenu(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();

    $staff = enterTenantPanel($tenant, RoleEnum::Staff);

    expect($staff->can(PermissionEnum::StorefrontView->value))->toBeTrue()
        ->and($staff->can('viewAny', HomeTile::class))->toBeTrue()
        ->and($staff->can('create', HomeTile::class))->toBeFalse()
        ->and($staff->can('update', $tile))->toBeFalse()
        ->and($staff->can('reorder', HomeTile::class))->toBeFalse();
});

it('keeps someone with no role off the home screen page', function (): void {
    $tenant = Tenant::factory()->create();
    $nobody = User::factory()->ofTenant($tenant)->create();

    expect($nobody->can('viewAny', HomeTile::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Making a tile
|--------------------------------------------------------------------------
*/

it('creates a tile that opens one of this tenant\'s menus', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::English->value => 'Our menu', Locale::Tamil->value => 'எங்கள் மெனு'],
            'action' => HomeTileAction::Menu->value,
            'menu_id' => $menu->getKey(),
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $tile = tileLabelled('Our menu');

    expect($tile->tenant_id)->toBe($tenant->getKey())
        ->and($tile->action)->toBe(HomeTileAction::Menu)
        ->and($tile->menu_id)->toBe($menu->getKey())
        ->and($tile->document_path)->toBeNull()
        ->and($tile->getTranslation('label', Locale::Tamil->value))->toBe('எங்கள் மெனு');
});

it('creates a tile that shows an uploaded PDF', function (): void {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Owner);
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::English->value => 'Wine list'],
            'action' => HomeTileAction::Pdf->value,
            'document_path' => UploadedFile::fake()->create('wine.pdf', 16, 'application/pdf'),
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $tile = tileLabelled('Wine list');

    expect($tile->action)->toBe(HomeTileAction::Pdf)
        ->and($tile->menu_id)->toBeNull()
        ->and($tile->document_path)->not->toBeNull();

    // A directory per tenant, so uploads are separated on the disk as well
    // as by the route that serves them.
    expect($tile->document_path)->toStartWith('home-tiles/'.$tenant->getKey().'/')
        ->and(Storage::disk('local')->exists($tile->document_path))->toBeTrue();
});

it('stores a tile\'s picture on the private disk', function (): void {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::English->value => 'Our menu'],
            'image_path' => UploadedFile::fake()->image('tile.jpg', 1200, 675),
            'action' => HomeTileAction::Menu->value,
            'menu_id' => $menu->getKey(),
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $tile = tileLabelled('Our menu');

    expect($tile->hasImage())->toBeTrue()
        ->and(Storage::disk('local')->exists($tile->image_path))->toBeTrue();
});

it('lets a tile be created with no picture yet', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    // A tenant arranging its home screen before it has photography is the
    // normal state on day one; the guest app draws the label instead.
    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::English->value => 'Our menu'],
            'action' => HomeTileAction::Menu->value,
            'menu_id' => $menu->getKey(),
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    expect(tileLabelled('Our menu')->hasImage())->toBeFalse();
});

it('requires the label in the fallback language', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::Tamil->value => 'எங்கள் மெனு'],
            'action' => HomeTileAction::Menu->value,
            'menu_id' => $menu->getKey(),
            'is_active' => true,
        ])
        ->assertHasActionErrors(['label.'.Locale::English->value]);
});

/*
|--------------------------------------------------------------------------
| A tile must go somewhere
|--------------------------------------------------------------------------
*/

it('refuses a menu tile with no menu chosen', function (): void {
    $tenant = Tenant::factory()->create();
    Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::English->value => 'Nowhere'],
            'action' => HomeTileAction::Menu->value,
            'is_active' => true,
        ])
        ->assertHasActionErrors(['menu_id']);
});

it('refuses a PDF tile with no file uploaded', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Owner);
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::English->value => 'Nowhere'],
            'action' => HomeTileAction::Pdf->value,
            'is_active' => true,
        ])
        ->assertHasActionErrors(['document_path']);
});

it('refuses to save a tile with no destination, whatever the form says', function (): void {
    $tenant = Tenant::factory()->create();

    // The second half of the same rule, at the model — with a CHECK constraint
    // behind it for anything that goes around the model altogether.
    expect(fn () => HomeTile::query()->create([
        'label' => [Locale::English->value => 'Nowhere'],
        'action' => HomeTileAction::Menu,
    ])->forceFill(['tenant_id' => $tenant->getKey()])->save())
        ->toThrow(LogicException::class);
});

it('clears the destination a tile no longer uses when its action changes', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $tile = HomeTile::factory()->openingMenu($menu)->create();

    // Switching to a PDF must not leave the old menu id behind, or the tile
    // would carry two destinations and only one of them would be used.
    $tile->update([
        'action' => HomeTileAction::Pdf,
        'document_path' => 'home-tiles/'.$tenant->getKey().'/wine.pdf',
    ]);

    expect($tile->refresh()->menu_id)->toBeNull()
        ->and($tile->document_path)->toBe('home-tiles/'.$tenant->getKey().'/wine.pdf');
});

/*
|--------------------------------------------------------------------------
| Arranging and editing
|--------------------------------------------------------------------------
*/

it('fills the edit form with every language, not just the current one', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $row = HomeRow::factory()->ofTenant($tenant)->create();
    $tile = HomeTile::factory()->openingMenu($menu)->inRow($row)->create([
        'label' => [Locale::English->value => 'Our menu', Locale::Tamil->value => 'எங்கள் மெனு'],
    ]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->mountAction(TestAction::make('edit')->table($tile))
        ->assertActionDataSet([
            'label' => [Locale::English->value => 'Our menu', Locale::Tamil->value => 'எங்கள் மெனு'],
        ]);
});

it('shows only the tiles in the row being edited', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    $myRow = HomeRow::factory()->ofTenant($mine)->create();
    $myTile = HomeTile::factory()
        ->openingMenu(Menu::factory()->create(['tenant_id' => $mine->getKey()]))
        ->inRow($myRow)
        ->create();

    // Another tenant's tile, which this row could not hold even if the
    // relation manager forgot to scope: HomeTileObserver refuses it.
    $theirRow = HomeRow::factory()->ofTenant($theirs)->create();
    $theirTile = HomeTile::factory()
        ->openingMenu(Menu::factory()->create(['tenant_id' => $theirs->getKey()]))
        ->inRow($theirRow)
        ->create();

    enterTenantPanel($mine, RoleEnum::Owner);

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $myRow, 'pageClass' => EditHomeRow::class])
        ->assertCanSeeTableRecords([$myTile])
        ->assertCanNotSeeTableRecords([$theirTile]);
});

it('orders the tiles the way the home screen shows them', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $row = HomeRow::factory()->ofTenant($tenant)->create();
    $last = HomeTile::factory()->openingMenu($menu)->inRow($row)->create(['position' => 5]);
    $first = HomeTile::factory()->openingMenu($menu)->inRow($row)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->assertCanSeeTableRecords([$first, $last], inOrder: true);
});

it('leaves a menu alone when a tile pointing at it is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $tile = HomeTile::factory()->openingMenu($menu)->create();

    $tile->delete();

    expect(Menu::query()->whereKey($menu->getKey())->exists())->toBeTrue();
});

it('takes a tenant\'s tiles with it when the menu they open is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $tile = HomeTile::factory()->openingMenu($menu)->create();

    // The cascade is deliberate: a tile whose menu is gone would open nothing.
    $menu->delete();

    expect(HomeTile::query()->whereKey($tile->getKey())->exists())->toBeFalse();
});
