<?php

use App\Enums\ChargeCalculation;
use App\Enums\Locale;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\Charges\Pages\ListCharges;
use App\Models\Charge;
use App\Models\Menu;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Charges
|--------------------------------------------------------------------------
|
| What a tenant adds to a guest's bill beyond the price: a share of the bill
| or a fixed amount, on the menus chosen for it. These replaced two
| fixed switches on the Settings page, which could say a service charge and a
| packing charge and nothing else.
|
*/

/**
 * Find a charge by the English half of its translated name.
 */
function chargeNamed(string $name): Charge
{
    return Charge::query()
        ->withoutGlobalScopes()
        ->where('name->'.Locale::English->value, $name)
        ->sole();
}

it('types a share of the bill as a percentage and stores it as basis points', function (): void {
    $tenant = Tenant::factory()->create();
    $menus = Menu::factory()->count(2)->create(['tenant_id' => $tenant->getKey()]);
    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListCharges::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Service Charge'],
            'calculation' => ChargeCalculation::Percentage->value,
            'rate_percentage' => '10',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $charge = chargeNamed('Service Charge');

    // Basis points, like a tax rate, so the arithmetic behind a bill stays in
    // integers: 10% of ₹500.00 is exactly ₹50.00.
    expect($charge->tenant_id)->toBe($tenant->getKey())
        ->and($charge->rate_basis_points)->toBe(1000)
        ->and($charge->amount_minor_units)->toBeNull()
        ->and($charge->amountOn(50000))->toBe(5000)
        // No menu was unpicked, so it starts on every menu the tenant has.
        ->and($charge->menus()->orderBy('menus.id')->pluck('menus.id')->all())->toBe($menus->pluck('id')->sort()->values()->all());
});

it('types a fixed amount as money and stores it in minor units', function (): void {
    $tenant = Tenant::factory()->create();
    Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListCharges::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Packing Charge'],
            'calculation' => ChargeCalculation::FixedAmount->value,
            'amount' => '20.50',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $charge = chargeNamed('Packing Charge');

    // The same sum whatever the bill comes to.
    expect($charge->amount_minor_units)->toBe(2050)
        ->and($charge->rate_basis_points)->toBeNull()
        ->and($charge->amountOn(50000))->toBe(2050)
        ->and($charge->amountOn(0))->toBe(2050);
});

it('limits a charge to the menus chosen for it', function (): void {
    $tenant = Tenant::factory()->create();
    $inRoomDining = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);
    $roomRequests = Menu::factory()->create(['tenant_id' => $tenant->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListCharges::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Room Service Fee'],
            'calculation' => ChargeCalculation::FixedAmount->value,
            'amount' => '50',
            'menus' => [$inRoomDining->getKey()],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $charge = chargeNamed('Room Service Fee');

    expect($charge->menus()->pluck('menus.id')->all())->toBe([$inRoomDining->getKey()])
        ->and(Charge::query()->withoutGlobalScopes()->forMenu($inRoomDining->getKey())->pluck('id')->all())->toBe([$charge->getKey()])
        ->and(Charge::query()->withoutGlobalScopes()->forMenu($roomRequests->getKey())->exists())->toBeFalse();
});

it('asks which menus a charge is added to', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListCharges::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Room Service Fee'],
            'calculation' => ChargeCalculation::FixedAmount->value,
            'amount' => '50',
            'menus' => [],
            'is_active' => true,
        ])
        ->assertHasActionErrors(['menus' => 'required']);

    expect(Charge::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('refuses another tenant\'s menu for a charge', function (): void {
    $tenant = Tenant::factory()->create();
    $theirMenu = Menu::factory()->create(['tenant_id' => Tenant::factory()->create()->getKey()]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // charge_menu has no tenant of its own, so the form is what refuses it.
    Livewire::test(ListCharges::class)
        ->callAction('create', [
            'name' => [Locale::English->value => 'Room Service Fee'],
            'calculation' => ChargeCalculation::FixedAmount->value,
            'amount' => '50',
            'menus' => [$theirMenu->getKey()],
            'is_active' => true,
        ])
        ->assertHasActionErrors(['menus']);

    expect(Charge::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('clears the number a charge stops using when its calculation changes', function (): void {
    $tenant = Tenant::factory()->create();
    $charge = Charge::factory()
        ->percentage(1000)
        ->onMenus(Menu::factory()->create(['tenant_id' => $tenant->getKey()]))
        ->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListCharges::class)
        ->callAction(TestAction::make('edit')->table($charge), [
            'name' => $charge->getTranslations('name'),
            'calculation' => ChargeCalculation::FixedAmount->value,
            'amount' => '50',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    expect($charge->refresh()->calculation)->toBe(ChargeCalculation::FixedAmount)
        ->and($charge->amount_minor_units)->toBe(5000)
        ->and($charge->rate_basis_points)->toBeNull();
});

it('refuses a charge with no number to add, even around the form', function (): void {
    // ChargeObserver clears the rate a fixed amount does not use, and then has
    // no amount to keep.
    expect(fn () => Charge::factory()->create([
        'calculation' => ChargeCalculation::FixedAmount,
        'rate_basis_points' => 1000,
        'amount_minor_units' => null,
    ]))->toThrow(LogicException::class);
});

it('lets a tenant owner manage charges', function (): void {
    $owner = enterTenantPanel(Tenant::factory()->create(), RoleEnum::Owner);

    expect($owner->can('viewAny', Charge::class))->toBeTrue()
        ->and($owner->can('create', Charge::class))->toBeTrue()
        ->and($owner->can('reorder', Charge::class))->toBeTrue();
});

it('keeps floor staff away from charges', function (): void {
    $staff = enterTenantPanel(Tenant::factory()->create(), RoleEnum::Staff);

    // The same people who could not change charges on the Settings page.
    expect($staff->can('viewAny', Charge::class))->toBeFalse()
        ->and($staff->can('create', Charge::class))->toBeFalse()
        ->and($staff->can('reorder', Charge::class))->toBeFalse();
});

it('shows only this tenant\'s charges', function (): void {
    $tenant = Tenant::factory()->create();
    $mine = Charge::factory()->ofTenant($tenant)->create();
    $theirs = Charge::factory()->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListCharges::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('puts charges in the order they are dragged into', function (): void {
    $tenant = Tenant::factory()->create();
    $first = Charge::factory()->ofTenant($tenant)->create(['position' => 0]);
    $second = Charge::factory()->ofTenant($tenant)->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListCharges::class)
        ->call('reorderTable', [(string) $second->getKey(), (string) $first->getKey()]);

    expect($second->refresh()->position)->toBeLessThan($first->refresh()->position);
});
