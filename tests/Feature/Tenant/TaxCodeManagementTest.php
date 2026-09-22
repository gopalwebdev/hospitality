<?php

use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\TaxCodes\Pages\ListTaxCodes;
use App\Models\TaxCode;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| HSN and SAC codes
|--------------------------------------------------------------------------
|
| A list a tenant files items under, so nobody types a GST rate per item from
| memory. Half of it is the catalogue the product team seeds — shared, and
| read-only to a tenant — and half is whatever that tenant added for itself.
|
*/

it('lists the shared catalogue beside this tenant\'s own, and nobody else\'s', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    $catalogue = TaxCode::factory()->create(['code' => '996331', 'description' => 'Restaurant service']);
    $own = TaxCode::factory()->ofTenant($tenant)->create(['code' => '996332', 'description' => 'Room service']);
    $theirs = TaxCode::factory()->ofTenant($other)->create(['code' => '999799', 'description' => 'Something else']);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListTaxCodes::class)
        ->assertCanSeeTableRecords([$catalogue, $own])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('stamps a code a tenant adds with that tenant, so it never joins the catalogue', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListTaxCodes::class)
        ->callAction('create', [
            'code' => '996311',
            'description' => 'Room accommodation',
            'tax_rate_percentage' => '18',
        ])
        ->assertHasNoActionErrors();

    $code = TaxCode::query()->where('code', '996311')->sole();

    // Basis points, like every rate here, converted in TaxCodeForm and nowhere else.
    expect($code->tenant_id)->toBe($tenant->getKey())
        ->and($code->tax_rate)->toBe(1800)
        ->and($code->isFromCatalogue())->toBeFalse();
});

it('refuses to let a tenant edit or delete a catalogue row it can see', function (): void {
    $tenant = Tenant::factory()->create();
    $catalogue = TaxCode::factory()->create(['tax_rate' => 500]);
    $own = TaxCode::factory()->ofTenant($tenant)->create();

    $user = enterTenantPanel($tenant, RoleEnum::Owner);

    // One tenant editing a shared row would reprice every other tenant's next
    // item, so the policy refuses it even though the row is on their screen.
    expect($user->can('update', $catalogue))->toBeFalse()
        ->and($user->can('delete', $catalogue))->toBeFalse()
        ->and($user->can('update', $own))->toBeTrue()
        ->and($user->can('delete', $own))->toBeTrue();

    Livewire::test(ListTaxCodes::class)
        ->assertTableActionHidden('edit', $catalogue)
        ->assertTableActionVisible('edit', $own);
});

it('offers the catalogue and this tenant\'s own to an item, and nobody else\'s', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    TaxCode::factory()->create(['code' => '996331']);
    TaxCode::factory()->ofTenant($tenant)->create(['code' => '996332']);
    TaxCode::factory()->ofTenant($other)->create(['code' => '999799']);

    enterTenantPanel($tenant, RoleEnum::Owner);

    $offered = TaxCode::query()->availableTo($tenant)->pluck('code')->sort()->values()->all();

    expect($offered)->toBe(['996331', '996332']);
});
