<?php

use App\Enums\Role;
use App\Models\HomeTile;
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
    Tenant::query()->get()->each(function (Tenant $tenant): void {
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
    Tenant::query()->get()->each(function (Tenant $tenant): void {
        $menus = $tenant->menus()->pluck('id');

        // A guest only ever reaches a menu through a tile, so a seeded card
        // with no tile is a card nobody at a table can get to. There were three
        // menus and one tile.
        expect($menus)->toHaveCount(3)
            ->and(HomeTile::query()->where('tenant_id', $tenant->getKey())->pluck('menu_id')->sort()->values()->all())
            ->toBe($menus->sort()->values()->all());
    });
});

it('can be seeded again without duplicating anything', function (): void {
    $this->seed(DatabaseSeeder::class);

    // The product team, plus an owner and a staff member for each tenant,
    // both of whom sign in to that tenant's panel at its /login.
    $perTenant = 2;

    expect(User::query()->count())->toBe(1 + ($perTenant * count(TenantSeeder::TENANTS)))
        ->and(Tenant::query()->count())->toBe(count(TenantSeeder::TENANTS));

    Tenant::query()->get()->each(function (Tenant $tenant) use ($perTenant): void {
        expect($tenant->users()->count())->toBe($perTenant)
            ->and($tenant->settings()->count())->toBe(1)
            // Tiles are matched on the menu they open rather than on their
            // label, so a relabelled tile is found rather than seeded again.
            ->and(HomeTile::query()->where('tenant_id', $tenant->getKey())->count())->toBe(3);
    });
});
