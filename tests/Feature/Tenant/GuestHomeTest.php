<?php

use App\Enums\HomeRowLayout;
use App\Enums\Locale;
use App\Models\HomeRow;
use App\Models\HomeTile;
use App\Models\Menu;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function homeUrl(Tenant $tenant): string
{
    return 'http://'.$tenant->slug.'.restaurant-app.test/';
}

function tileUrl(Tenant $tenant, HomeTile $tile, string $suffix = ''): string
{
    return 'http://'.$tenant->slug.'.restaurant-app.test/tiles/'.$tile->getKey().$suffix;
}

/*
|--------------------------------------------------------------------------
| The tiles a guest lands on
|--------------------------------------------------------------------------
*/

it('shows the tiles in the order the tenant arranged them', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    $second = HomeTile::factory()->openingMenu($menu)->inRow($row)->create([
        'label' => [Locale::English->value => 'Drinks'],
        'position' => 2,
    ]);
    $first = HomeTile::factory()->openingMenu($menu)->inRow($row)->create([
        'label' => [Locale::English->value => 'Food'],
        'position' => 1,
    ]);

    $this->get(homeUrl($tenant))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->has('rows', 1)
            ->has('rows.0.tiles', 2)
            ->where('rows.0.tiles.0.id', $first->getKey())
            ->where('rows.0.tiles.0.label', 'Food')
            ->where('rows.0.tiles.1.id', $second->getKey()),
        );
});

it('shows the rows in the order the tenant arranged them', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $second = HomeRow::factory()->ofTenant($tenant)->titled('Offers')->create(['position' => 2]);
    $first = HomeRow::factory()->ofTenant($tenant)->create(['position' => 1]);

    HomeTile::factory()->openingMenu($menu)->inRow($second)->create();
    HomeTile::factory()->openingMenu($menu)->inRow($first)->create();

    $this->get(homeUrl($tenant))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('rows', 2)
            ->where('rows.0.id', $first->getKey())
            ->where('rows.0.title', null)
            ->where('rows.1.id', $second->getKey())
            ->where('rows.1.title', 'Offers'),
        );
});

it('sends each row the shape its layout is drawn at', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $row = HomeRow::factory()->ofTenant($tenant)->layout(HomeRowLayout::Links)->create();

    HomeTile::factory()->linkingTo('https://instagram.com/spice')->inRow($row)->create();

    // Decided server side so the layout stored is the layout rendered, and
    // there is no second list in React to keep in step.
    $this->get(homeUrl($tenant))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('rows.0.layout', HomeRowLayout::Links->value)
            ->where('rows.0.aspectRatio', HomeRowLayout::Links->aspectRatio())
            ->where('rows.0.isScrollable', true)
            ->where('rows.0.isCircular', true)
            ->where('rows.0.tiles.0.href', 'https://instagram.com/spice')
            ->where('rows.0.tiles.0.isExternal', true),
        );
});

it('leaves a hidden row off the home screen', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $showing = HomeRow::factory()->ofTenant($tenant)->create();
    $hidden = HomeRow::factory()->ofTenant($tenant)->hidden()->create();

    HomeTile::factory()->openingMenu($menu)->inRow($showing)->create(['label' => [Locale::English->value => 'Showing']]);
    HomeTile::factory()->openingMenu($menu)->inRow($hidden)->create(['label' => [Locale::English->value => 'Hidden']]);

    $this->get(homeUrl($tenant))
        ->assertOk()
        ->assertSee('Showing')
        ->assertDontSee('Hidden');
});

it('drops a row once every tile in it leads nowhere', function (): void {
    $tenant = Tenant::factory()->create();
    $hiddenMenu = Menu::factory()->hidden()->create(['tenant_id' => $tenant->getKey()]);
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    // An empty band is worse than no band: it reads as something that failed
    // to load rather than as a row the tenant has not filled in.
    HomeTile::factory()->openingMenu($hiddenMenu)->inRow($row)->create();

    $this->get(homeUrl($tenant))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('rows', 0));
});

it('leaves a hidden tile off the home screen', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    HomeTile::factory()->openingMenu($menu)->inRow($row)->create(['label' => [Locale::English->value => 'Showing']]);
    HomeTile::factory()->openingMenu($menu)->inRow($row)->hidden()->create(['label' => [Locale::English->value => 'Hidden']]);

    $this->get(homeUrl($tenant))
        ->assertOk()
        ->assertSee('Showing')
        ->assertDontSee('Hidden');
});

it('drops a tile whose menu has been taken down', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $hiddenMenu = Menu::factory()->hidden()->create(['tenant_id' => $tenant->getKey()]);
    $row = HomeRow::factory()->ofTenant($tenant)->create();

    // The record is still there and the foreign key is satisfied, so nothing is
    // broken — but tapping it would open a menu the tenant took down.
    HomeTile::factory()->openingMenu($hiddenMenu)->inRow($row)->create();
    $survivor = HomeTile::factory()->openingMenu($menu)->inRow($row)->create();

    $this->get(homeUrl($tenant))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('rows.0.tiles', 1)
            ->where('rows.0.tiles.0.id', $survivor->getKey()),
        );
});

it('sends a menu tile to that menu and a PDF tile to its own page', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    $row = HomeRow::factory()->ofTenant($tenant)->create();
    $menuTile = HomeTile::factory()->openingMenu($menu)->inRow($row)->create(['position' => 0]);
    $pdfTile = HomeTile::factory()->showingPdf()->inRow($row)->create(['position' => 1]);

    $this->get(homeUrl($tenant))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('rows.0.tiles.0.id', $menuTile->getKey())
            ->where('rows.0.tiles.0.href', route('guest.menus.show', ['tenant' => $tenant->slug, 'menu' => $menu->getKey()]))
            ->where('rows.0.tiles.0.isExternal', false)
            ->where('rows.0.tiles.1.id', $pdfTile->getKey())
            ->where('rows.0.tiles.1.href', route('guest.tiles.show', ['tenant' => $tenant->slug, 'tile' => $pdfTile->getKey()])),
        );
});

it('sends no image url for a tile with no picture yet', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    HomeTile::factory()
        ->openingMenu($menu)
        ->inRow(HomeRow::factory()->ofTenant($tenant)->create())
        ->withoutImage()
        ->create();

    // A tile without a picture is not broken: the guest app draws its label on
    // the brand colour instead.
    $this->get(homeUrl($tenant))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('rows.0.tiles.0.imageUrl', null));
});

it('shows only this tenant\'s tiles', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    HomeTile::factory()
        ->openingMenu(Menu::factory()->create(['tenant_id' => $mine->getKey()]))
        ->create(['label' => [Locale::English->value => 'Mine']]);

    HomeTile::factory()
        ->openingMenu(Menu::factory()->create(['tenant_id' => $theirs->getKey()]))
        ->create(['label' => [Locale::English->value => 'Theirs']]);

    $this->get(homeUrl($mine))
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

/*
|--------------------------------------------------------------------------
| The PDF behind a tile
|--------------------------------------------------------------------------
*/

it('shows a PDF tile inside the app, with the file behind its own route', function (): void {
    $tenant = Tenant::factory()->create();
    $tile = HomeTile::factory()->showingPdf()->create([
        'tenant_id' => $tenant->getKey(),
        'label' => [Locale::English->value => 'Wine list'],
    ]);

    // Embedded rather than opened in the browser's own viewer, so the app keeps
    // its header and the guest has a back arrow out of it.
    $this->get(tileUrl($tenant, $tile))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('document')
            ->where('title', 'Wine list')
            ->where('documentUrl', route('guest.tiles.document.show', [
                'tenant' => $tenant->slug,
                'tile' => $tile->getKey(),
            ])),
        );
});

it('serves a tile\'s PDF inline off the private disk', function (): void {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $path = Storage::disk('local')->putFile(
        'home-tiles/'.$tenant->getKey(),
        UploadedFile::fake()->create('wine.pdf', 8, 'application/pdf'),
    );

    $tile = HomeTile::factory()->showingPdf($path)->create(['tenant_id' => $tenant->getKey()]);

    $this->get(tileUrl($tenant, $tile, '/document'))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline');
});

it('serves a tile\'s picture off the private disk', function (): void {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $path = Storage::disk('local')->putFile(
        'home-tiles/'.$tenant->getKey(),
        UploadedFile::fake()->image('tile.jpg'),
    );

    $tile = HomeTile::factory()->openingMenu($menu)->create(['image_path' => $path]);

    $this->get(tileUrl($tenant, $tile, '/image'))->assertOk();
});

it('404s a file whose upload has gone missing', function (): void {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $tile = HomeTile::factory()->showingPdf('home-tiles/1/vanished.pdf')->create([
        'tenant_id' => $tenant->getKey(),
    ]);

    $this->get(tileUrl($tenant, $tile, '/document'))->assertNotFound();
});

it('refuses a tile that is not this tenant\'s', function (): void {
    Storage::fake('local');

    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    $path = Storage::disk('local')->putFile(
        'home-tiles/'.$theirs->getKey(),
        UploadedFile::fake()->create('secret.pdf', 8, 'application/pdf'),
    );

    $theirTile = HomeTile::factory()->showingPdf($path)->create(['tenant_id' => $theirs->getKey()]);

    // The tenant arrives in the domain rather than the path, so scoped
    // bindings do not cover it: editing the id in the URL would otherwise read
    // another tenant's files off the same disk.
    $this->get(tileUrl($mine, $theirTile))->assertNotFound();
    $this->get(tileUrl($mine, $theirTile, '/document'))->assertNotFound();
    $this->get(tileUrl($mine, $theirTile, '/image'))->assertNotFound();
});

it('refuses a hidden tile\'s page and files', function (): void {
    $tenant = Tenant::factory()->create();
    $tile = HomeTile::factory()->showingPdf()->hidden()->create(['tenant_id' => $tenant->getKey()]);

    $this->get(tileUrl($tenant, $tile))->assertNotFound();
    $this->get(tileUrl($tenant, $tile, '/document'))->assertNotFound();
});

it('refuses a menu tile\'s document page, which it has none of', function (): void {
    $tenant = Tenant::factory()->create();
    $menu = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $tile = HomeTile::factory()->openingMenu($menu)->create();

    $this->get(tileUrl($tenant, $tile))->assertNotFound();
});

it('takes a tenant\'s whole storefront offline when it is switched off', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => false]);
    $tile = HomeTile::factory()->showingPdf()->create(['tenant_id' => $tenant->getKey()]);

    $this->get(homeUrl($tenant))->assertNotFound();
    $this->get(tileUrl($tenant, $tile))->assertNotFound();
    $this->get(tileUrl($tenant, $tile, '/document'))->assertNotFound();
});
