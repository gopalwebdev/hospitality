<?php

use App\Enums\Diet;
use App\Enums\HomeTileAction;
use App\Enums\Role;
use App\Models\Charge;
use App\Models\HomeTile;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuRail;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('seeds exactly one product team owner', function (): void {
    $admins = User::query()->admins()->get();

    expect($admins)->toHaveCount(1)
        ->and($admins->first()->email)->toBe(AdminSeeder::EMAIL);
});

it('gives the product team owner no password to store', function (): void {
    // The users table carries no password column at all, so there is nothing
    // to leave null and nothing to leak.
    expect(Schema::hasColumn('users', 'password'))->toBeFalse();
});

it('seeds one tenant for each definition', function (): void {
    expect(Tenant::query()->count())->toBe(count(TenantSeeder::TENANTS));

    foreach (TenantSeeder::TENANTS as $definition) {
        expect(Tenant::query()->where('slug', $definition['slug'])->exists())->toBeTrue();
    }
});

it('gives every seeded tenant its settings', function (): void {
    // Loaded with the tenants: there is more than one, and strict mode refuses
    // a lazy load down a list.
    Tenant::query()->with('settings')->get()->each(function (Tenant $tenant): void {
        expect($tenant->settings)->not->toBeNull();
    });
});

it('gives every seeded tenant exactly one owner', function (): void {
    Tenant::query()->get()->each(function (Tenant $tenant): void {
        $owners = $tenant->users()->role(Role::Owner->value)->get();

        expect($owners)->toHaveCount(1);
    });
});

it('belongs the seeded owner to their tenant, not the product team', function (): void {
    Tenant::query()->get()->each(function (Tenant $tenant): void {
        $owner = $tenant->users()->role(Role::Owner->value)->firstOrFail();

        expect($owner->tenant_id)->toBe($tenant->getKey())
            ->and($owner->belongsToProductTeam())->toBeFalse();
    });
});

it('lets each tenant owner into their own panel and no further', function (): void {
    $tenant = Tenant::query()->firstOrFail();
    $owner = $tenant->users()->role(Role::Owner->value)->firstOrFail();

    $this->actingAs($owner)
        ->get("http://{$tenant->slug}.hospitality.test/dashboard")
        ->assertOk();

    $this->actingAs($owner)
        ->get('http://hospitality.test/dashboard')
        ->assertForbidden();
});

it('lets the product team owner into the product team panel', function (): void {
    $admin = User::query()->where('email', AdminSeeder::EMAIL)->firstOrFail();

    $this->actingAs($admin)
        ->get('http://hospitality.test/dashboard')
        ->assertOk();
});

it('lists every account and where it signs in', function (): void {
    $tenant = Tenant::query()->firstOrFail();
    $owner = $tenant->users()->role(Role::Owner->value)->firstOrFail();

    expect(Artisan::call('accounts:list'))->toBe(0);

    $output = Artisan::output();

    expect($output)
        ->toContain(AdminSeeder::EMAIL)
        // /login on each host: the one address to hand anyone who uses a panel.
        ->toContain('hospitality.test/login')
        ->toContain($owner->email)
        ->toContain($tenant->slug.'.hospitality.test/login');
});

it('opens a way into every menu it seeds', function (): void {
    foreach (TenantSeeder::TENANTS as $definition) {
        $tenant = Tenant::query()->where('slug', $definition['slug'])->sole();
        $menus = $tenant->menus()->pluck('id');

        // A guest only ever reaches a menu through a tile, so a seeded card
        // with no tile is a card nobody at a table can get to. There were three
        // menus and one tile. Scoped to the menu-opening tiles: the seeder
        // also seeds a couple of social links and a PDF, which name no menu
        // at all (App\Enums\HomeTileAction::Link / ::Pdf).
        expect($menus)->toHaveCount(count($definition['menus']))
            ->and(
                HomeTile::query()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('action', HomeTileAction::Menu)
                    ->pluck('menu_id')
                    ->sort()
                    ->values()
                    ->all(),
            )
            ->toBe($menus->sort()->values()->all());
    }
});

it('places every seeded menu\'s rails, never leaving menu_rails empty', function (): void {
    // Every card in TenantSeeder::CARDS names at least one rail position
    // (TenantSeeder::seedRails()), so a menu nobody has arranged by hand
    // should never happen on a fresh install — that was the gap this
    // seeder used to leave: menu_rails at zero rows, and Menu::readingOrder()
    // never exercised beyond its own "nobody has arranged this" fallback.
    expect(MenuRail::query()->count())->toBeGreaterThan(0);

    foreach (Tenant::query()->get() as $tenant) {
        foreach ($tenant->menus()->get() as $menu) {
            expect(MenuRail::query()->where('menu_id', $menu->getKey())->exists())
                ->toBeTrue("Expected {$menu->name} to have at least one placed rail.");
        }
    }
});

it('can be seeded again without duplicating anything', function (): void {
    $this->seed(DatabaseSeeder::class);

    // The product team, plus an owner and a staff member for each tenant,
    // both of whom sign in to that tenant's panel at its /login.
    $perTenant = 2;

    expect(User::query()->count())->toBe(1 + ($perTenant * count(TenantSeeder::TENANTS)))
        ->and(Tenant::query()->count())->toBe(count(TenantSeeder::TENANTS));

    foreach (TenantSeeder::TENANTS as $definition) {
        $tenant = Tenant::query()->where('slug', $definition['slug'])->sole();

        // A tile that opens a menu is matched on the menu itself rather than
        // on its label, so a relabelled tile is found rather than seeded
        // again; a social link or the PDF is matched on its own English
        // label the same way (HomeRowLayout::Links and ::Carousel — see
        // TenantSeeder::seedHomeScreen()) — two links and one PDF, once per
        // tenant, alongside its one tile per menu.
        expect($tenant->users()->count())->toBe($perTenant)
            ->and($tenant->settings()->count())->toBe(1)
            ->and(HomeTile::query()->where('tenant_id', $tenant->getKey())->where('action', HomeTileAction::Menu)->count())->toBe(count($definition['menus']))
            ->and(HomeTile::query()->where('tenant_id', $tenant->getKey())->count())->toBe(count($definition['menus']) + 3)
            // Charges are matched on their English name the same way.
            ->and($tenant->charges()->count())->toBe(count(TenantSeeder::CHARGES[$definition['slug']] ?? []))
            // A rail is matched on its (menu, type) pair, which is exactly
            // as stable across a re-seed as an English name is.
            ->and(MenuRail::query()->where('tenant_id', $tenant->getKey())->count())->toBe(
                array_sum(array_map(
                    static fn (string $card): int => count(TenantSeeder::CARDS[$card]['rails']),
                    $definition['menus'],
                )),
            );
    }
});

it('seeds a hotel whose room requests share a menu with things to order', function (): void {
    $hotel = Tenant::query()->where('slug', 'seaview')->sole();

    $pillow = MenuItem::query()
        ->where('tenant_id', $hotel->getKey())
        ->where('name->en', 'Extra Pillow')
        ->sole();
    $water = MenuItem::query()
        ->where('tenant_id', $hotel->getKey())
        ->where('name->en', 'Water Bottle (1 L)')
        ->sole();

    $menusHoldingThem = MenuCategory::query()
        ->whereKey([$pillow->menu_category_id, $water->menu_category_id])
        ->pluck('menu_id')
        ->unique();

    // One card holds both: a service request with no diet mark and no price,
    // and something to order with both.
    expect($menusHoldingThem)->toHaveCount(1)
        ->and($pillow->is_service_request)->toBeTrue()
        ->and($pillow->diets)->toBeNull()
        ->and($pillow->isComplimentary())->toBeTrue()
        ->and($water->is_service_request)->toBeFalse()
        ->and($water->dietMark())->toBe(Diet::Vegetarian)
        ->and($water->isComplimentary())->toBeFalse();
});

it('limits a seeded charge to the menus it names', function (): void {
    $hotel = Tenant::query()->where('slug', 'seaview')->sole();

    $inRoomDining = $hotel->menus()->where('name->en', 'In-room Dining')->sole();
    $roomRequests = $hotel->menus()->where('name->en', 'Room Requests')->sole();

    $chargesOn = fn (Menu $menu): int => Charge::query()
        ->where('tenant_id', $hotel->getKey())
        ->active()
        ->forMenu($menu->getKey())
        ->count();

    // The room-service fee is added to what is brought to a room, and a pillow
    // asked for from housekeeping carries no charge at all.
    expect($chargesOn($inRoomDining))->toBe(1)
        ->and($chargesOn($roomRequests))->toBe(0);
});
