<?php

use App\Enums\HomeRowLayout;
use App\Enums\HomeTileAction;
use App\Enums\Locale;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Restaurant\Resources\HomeRows\Pages\EditHomeRow;
use App\Filament\Restaurant\Resources\HomeRows\Pages\ListHomeRows;
use App\Filament\Restaurant\Resources\HomeRows\RelationManagers\TilesRelationManager;
use App\Models\HomeRow;
use App\Models\HomeTile;
use App\Models\Menu;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
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

it('lets a restaurant admin arrange the rows', function (): void {
    $restaurant = Restaurant::factory()->create();
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();

    $admin = enterRestaurantPanel($restaurant, RoleEnum::Admin);

    expect($admin->can('viewAny', HomeRow::class))->toBeTrue()
        ->and($admin->can('create', HomeRow::class))->toBeTrue()
        ->and($admin->can('update', $row))->toBeTrue()
        ->and($admin->can('reorder', HomeRow::class))->toBeTrue();
});

it('lets staff see the rows but not rearrange them', function (): void {
    $restaurant = Restaurant::factory()->create();
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();

    $staff = enterRestaurantPanel($restaurant, RoleEnum::Staff);

    expect($staff->can(PermissionEnum::StorefrontView->value))->toBeTrue()
        ->and($staff->can('viewAny', HomeRow::class))->toBeTrue()
        ->and($staff->can('create', HomeRow::class))->toBeFalse()
        ->and($staff->can('update', $row))->toBeFalse();
});

it('keeps someone with no role off the home screen pages', function (): void {
    $restaurant = Restaurant::factory()->create();
    $nobody = User::factory()->ofRestaurant($restaurant)->create();

    expect($nobody->can('viewAny', HomeRow::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Making a row
|--------------------------------------------------------------------------
*/

it('creates a row against the restaurant whose panel it is', function (): void {
    $restaurant = Restaurant::factory()->create();
    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListHomeRows::class)
        ->callAction('create', [
            'title' => [Locale::English->value => 'This month', Locale::Tamil->value => 'இந்த மாதம்'],
            'layout' => HomeRowLayout::Carousel->value,
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $row = HomeRow::query()->withoutGlobalScopes()->sole();

    expect($row->tenant_id)->toBe($restaurant->getKey())
        ->and($row->layout)->toBe(HomeRowLayout::Carousel)
        ->and($row->title)->toBe('This month')
        ->and($row->getTranslation('title', Locale::Tamil->value))->toBe('இந்த மாதம்');
});

it('lets a row be created with no heading at all', function (): void {
    $restaurant = Restaurant::factory()->create();
    enterRestaurantPanel($restaurant, RoleEnum::Admin);

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

it('shows only this restaurant\'s rows', function (): void {
    $mine = Restaurant::factory()->create();
    $theirs = Restaurant::factory()->create();

    $myRow = HomeRow::factory()->ofRestaurant($mine)->create();
    $theirRow = HomeRow::factory()->ofRestaurant($theirs)->create();

    enterRestaurantPanel($mine, RoleEnum::Admin);

    Livewire::test(ListHomeRows::class)
        ->assertCanSeeTableRecords([$myRow])
        ->assertCanNotSeeTableRecords([$theirRow]);
});

it('orders the rows the way the home screen shows them', function (): void {
    $restaurant = Restaurant::factory()->create();

    $last = HomeRow::factory()->ofRestaurant($restaurant)->create(['position' => 5]);
    $first = HomeRow::factory()->ofRestaurant($restaurant)->create(['position' => 1]);

    enterRestaurantPanel($restaurant, RoleEnum::Admin);

    Livewire::test(ListHomeRows::class)
        ->assertCanSeeTableRecords([$first, $last], inOrder: true);
});

it('takes a row\'s tiles with it when it is deleted', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();
    $tile = HomeTile::factory()->openingMenu($menu)->inRow($row)->create();

    $row->delete();

    expect(HomeTile::query()->withoutGlobalScopes()->whereKey($tile->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Tenant isolation
|--------------------------------------------------------------------------
*/

it('refuses at the database to put a tile in another restaurant\'s row', function (): void {
    $mine = Restaurant::factory()->create();
    $theirs = Restaurant::factory()->create();
    $theirRow = HomeRow::factory()->ofRestaurant($theirs)->create();

    // The composite foreign key on (home_row_id, tenant_id) is what makes this
    // a database error rather than something a forgotten where() lets through.
    expect(fn () => HomeTile::factory()->create([
        'tenant_id' => $mine->getKey(),
        'home_row_id' => $theirRow->getKey(),
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Link tiles
|--------------------------------------------------------------------------
*/

it('creates a tile that leaves the app for a link', function (): void {
    $restaurant = Restaurant::factory()->create();
    enterRestaurantPanel($restaurant, RoleEnum::Admin);
    $row = HomeRow::factory()->ofRestaurant($restaurant)->layout(HomeRowLayout::Links)->create();

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
    $restaurant = Restaurant::factory()->create();
    enterRestaurantPanel($restaurant, RoleEnum::Admin);
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();

    Livewire::test(TilesRelationManager::class, ['ownerRecord' => $row, 'pageClass' => EditHomeRow::class])
        ->callAction(TestAction::make('create')->table(), [
            'label' => [Locale::English->value => 'Nowhere'],
            'action' => HomeTileAction::Link->value,
            'is_active' => true,
        ])
        ->assertHasActionErrors(['url']);
});

it('clears a link when a tile stops being one', function (): void {
    $restaurant = Restaurant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $restaurant->getKey()]);
    $row = HomeRow::factory()->ofRestaurant($restaurant)->create();
    $tile = HomeTile::factory()->linkingTo('https://example.com')->inRow($row)->create();

    $tile->update(['action' => HomeTileAction::Menu, 'menu_id' => $menu->getKey()]);

    expect($tile->refresh()->url)->toBeNull()
        ->and($tile->menu_id)->toBe($menu->getKey());
});
