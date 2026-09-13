<?php

use App\Enums\HomeTileAction;
use App\Enums\Locale;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Restaurant\Resources\HomeRows\Pages\EditHomeRow;
use App\Filament\Restaurant\Resources\HomeRows\RelationManagers\TilesRelationManager;
use App\Models\HomeRow;
use App\Models\HomeTile;
use App\Models\Menu;
use App\Models\Restaurant;
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
| shop window, and a restaurant may want someone who can rearrange it without
| also being able to rewrite prices.
|
*/

it('lets a restaurant admin arrange the home screen', function (): void {
    $restaurant = Restaurant::factory()->create();
    $tile = HomeTile::factory()
        ->openingMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();

    $admin = enterRestaurantPanel($restaurant, RoleEnum::Admin);

    expect($admin->can('viewAny', HomeTile::class))->toBeTrue()
        ->and($admin->can('create', HomeTile::class))->toBeTrue()
        ->and($admin->can('update', $tile))->toBeTrue()
        ->and($admin->can('delete', $tile))->toBeTrue()
        // Dragging the rows is the point of the page, and strictAuthorization
        // makes a missing reorder() a 500 rather than a refusal.
        ->and($admin->can('reorder', HomeTile::class))->toBeTrue();
});

it('lets staff see the home screen but not rearrange it', function (): void {
    $restaurant = Restaurant::factory()->create();
    $tile = HomeTile::factory()
        ->openingMenu(Menu::factory()->create(['tenant_id' => $restaurant->getKey()]))
        ->create();

    $staff = enterRestaurantPanel($restaurant, RoleEnum::Staff);

    expect($staff->can(PermissionEnum::StorefrontView->value))->toBeTrue()
        ->and($staff->can('viewAny', HomeTile::class))->toBeTrue()
        ->and($staff->can('create', HomeTile::class))->toBeFalse()
        ->and($staff->can('update', $tile))->toBeFalse()
        ->and($staff->can('reorder', HomeTile::class))->toBeFalse();
});

it('keeps someone with no role off the home screen page', function (): void {
    $restaurant = Restaurant::factory()->create();
    $nobody = User::factory()->ofRestaurant($restaurant)->create();

    expect($nobody->can('viewAny', HomeTile::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Making a tile
|--------------------------------------------------------------------------
*/

it('creates a tile that opens one of this restaurant\'s menus', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::English->value => 'Our menu', Locale::Tamil->value => 'எங்கள் மெனு'],
            'action' => HomeTileAction::Menu->value,
            'menu_id' => $menu->getKey(),
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $tile = tileLabelled('Our menu');

    expect($tile->tenant_id)->toBe($restaurant->getKey())
        ->and($tile->action)->toBe(HomeTileAction::Menu)
        ->and($tile->menu_id)->toBe($menu->getKey())
        ->and($tile->document_path)->toBeNull()
        ->and($tile->getTranslation('label', Locale::Tamil->value))->toBe('எங்கள் மெனு');
});

it('creates a tile that shows an uploaded PDF', function (): void {
    Storage::fake('local');

    $restaurant = Restaurant::factory()->create();
    enterRestaurantPanel($restaurant, RoleEnum::Admin);
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();

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

    // A directory per restaurant, so uploads are separated on the disk as well
    // as by the route that serves them.
    expect($tile->document_path)->toStartWith('home-tiles/'.$restaurant->getKey().'/')
        ->and(Storage::disk('local')->exists($tile->document_path))->toBeTrue();
});

it('stores a tile\'s picture on the private disk', function (): void {
    Storage::fake('local');

    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();

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
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();

    // A restaurant arranging its home screen before it has photography is the
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
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();

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
    $restaurant = Restaurant::factory()->create();
    Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::English->value => 'Nowhere'],
            'action' => HomeTileAction::Menu->value,
            'is_active' => true,
        ])
        ->assertHasActionErrors(['menu_id']);
});

it('refuses a PDF tile with no file uploaded', function (): void {
    $restaurant = Restaurant::factory()->create();
    enterRestaurantPanel($restaurant, RoleEnum::Admin);
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::English->value => 'Nowhere'],
            'action' => HomeTileAction::Pdf->value,
            'is_active' => true,
        ])
        ->assertHasActionErrors(['document_path']);
});

it('refuses to save a tile with no destination, whatever the form says', function (): void {
    $restaurant = Restaurant::factory()->create();

    // The second half of the same rule, at the model — with a CHECK constraint
    // behind it for anything that goes around the model altogether.
    expect(fn () => HomeTile::query()->create([
        'label' => [Locale::English->value => 'Nowhere'],
        'action' => HomeTileAction::Menu,
    ])->forceFill(['tenant_id' => $restaurant->getKey()])->save())
        ->toThrow(LogicException::class);
});

it('clears the destination a tile no longer uses when its action changes', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $tile = HomeTile::factory()->openingMenu($menu)->create();

    // Switching to a PDF must not leave the old menu id behind, or the tile
    // would carry two destinations and only one of them would be used.
    $tile->update([
        'action' => HomeTileAction::Pdf,
        'document_path' => 'home-tiles/'.$restaurant->getKey().'/wine.pdf',
    ]);

    expect($tile->refresh()->menu_id)->toBeNull()
        ->and($tile->document_path)->toBe('home-tiles/'.$restaurant->getKey().'/wine.pdf');
});

/*
|--------------------------------------------------------------------------
| Arranging and editing
|--------------------------------------------------------------------------
*/

it('fills the edit form with every language, not just the current one', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();
    $tile = HomeTile::factory()->openingMenu($menu)->inRow($row)->create([
        'label' => [Locale::English->value => 'Our menu', Locale::Tamil->value => 'எங்கள் மெனு'],
    ]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->mountAction(TestAction::make('edit')->table($tile))
        ->assertActionDataSet([
            'label' => [Locale::English->value => 'Our menu', Locale::Tamil->value => 'எங்கள் மெனு'],
        ]);
});

it('shows only the tiles in the row being edited', function (): void {
    $mine = Restaurant::factory()->create();
    $theirs = Restaurant::factory()->create();

    $myRow = HomeRow::factory()->ofRestaurant($mine)->create();
    $myTile = HomeTile::factory()
        ->openingMenu(Menu::factory()->create(['tenant_id' => $mine->getKey()]))
        ->inRow($myRow)
        ->create();

    // Another restaurant's tile, which this row could not hold even if the
    // relation manager forgot to scope: the composite foreign key on
    // (home_row_id, tenant_id) is what makes that a database error.
    $theirRow = HomeRow::factory()->ofRestaurant($theirs)->create();
    $theirTile = HomeTile::factory()
        ->openingMenu(Menu::factory()->create(['tenant_id' => $theirs->getKey()]))
        ->inRow($theirRow)
        ->create();

    enterRestaurantPanel($mine, RoleEnum::Admin);

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $myRow, 'pageClass' => EditHomeRow::class])
        ->assertCanSeeTableRecords([$myTile])
        ->assertCanNotSeeTableRecords([$theirTile]);
});

it('orders the tiles the way the home screen shows them', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);

    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();
    $last = HomeTile::factory()->openingMenu($menu)->inRow($row)->create(['position' => 5]);
    $first = HomeTile::factory()->openingMenu($menu)->inRow($row)->create(['position' => 1]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->assertCanSeeTableRecords([$first, $last], inOrder: true);
});

it('leaves a menu alone when a tile pointing at it is deleted', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $tile = HomeTile::factory()->openingMenu($menu)->create();

    $tile->delete();

    expect(Menu::query()->whereKey($menu->getKey())->exists())->toBeTrue();
});

it('takes a restaurant\'s tiles with it when the menu they open is deleted', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $tile = HomeTile::factory()->openingMenu($menu)->create();

    // The cascade is deliberate: a tile whose menu is gone would open nothing.
    $menu->delete();

    expect(HomeTile::query()->whereKey($tile->getKey())->exists())->toBeFalse();
});
