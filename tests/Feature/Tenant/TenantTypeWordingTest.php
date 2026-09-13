<?php

use App\Enums\Locale;
use App\Enums\Role as RoleEnum;
use App\Enums\TenantType;
use App\Filament\Tenant\CurrentTenant;
use App\Filament\Tenant\Resources\Menus\Pages\ListMenus;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| What a tenant's own people read it called
|--------------------------------------------------------------------------
|
| A hotel's admins and guests read "hotel", a restaurant's read "restaurant",
| and nobody but the product team reads "tenant". See App\Enums\TenantType.
|
*/

it('names the business by its type in the panel', function (TenantType $type, string $message): void {
    $tenant = Tenant::factory()->create(['type' => $type]);
    enterTenantPanel($tenant, RoleEnum::Admin);

    $dinner = ['name' => [Locale::English->value => 'Dinner'], 'is_active' => true];

    $page = Livewire::test(ListMenus::class)
        ->callAction('create', $dinner)
        ->assertHasNoActionErrors()
        ->callAction('create', $dinner);

    expect($page->errors()->all())->toContain($message);
})->with([
    'a hotel' => [TenantType::Hotel, 'This hotel already has a menu with that name.'],
    'a restaurant' => [TenantType::Restaurant, 'This restaurant already has a menu with that name.'],
]);

it('names every type where the panel knows no tenant yet', function (): void {
    expect(CurrentTenant::noun())->toBe('hotel or restaurant');

    enterTenantPanel(Tenant::factory()->hotel()->create(), RoleEnum::Admin);

    expect(CurrentTenant::noun())->toBe('hotel');
});

it('says hotel or restaurant on the sign-in page, before anyone is signed in', function (): void {
    Tenant::factory()->hotel()->create(['slug' => 't1']);

    $this->get('http://t1.restaurant-app.test/dashboard/login')
        ->assertOk()
        ->assertSee('Sign in to manage your hotel or restaurant.');
});

it('tells the guest app what to call the business', function (TenantType $type, string $noun): void {
    Tenant::factory()->create(['slug' => 't1', 'type' => $type]);

    $this->get('http://t1.restaurant-app.test/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('tenant.typeNoun', $noun));
})->with([
    'a hotel' => [TenantType::Hotel, 'hotel'],
    'a restaurant' => [TenantType::Restaurant, 'restaurant'],
]);
