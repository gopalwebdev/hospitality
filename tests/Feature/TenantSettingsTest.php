<?php

use App\Enums\Currency;
use App\Enums\FilamentPanel;
use App\Enums\Role;
use App\Filament\Tenant\Pages\Settings;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Reading settings
|--------------------------------------------------------------------------
*/

it('shows the settings of the tenant whose panel it is', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings()->update([
        'contact_email' => 'hello@spice.example.com',
    ]);
    enterTenantPanel($tenant, Role::Admin);

    Livewire::test(Settings::class)
        ->assertFormSet([
            'contact_email' => 'hello@spice.example.com',
        ]);
});

it('never shows another tenant settings', function (): void {
    $own = Tenant::factory()->create();
    $own->settings()->update(['contact_email' => 'ours@example.com']);

    $other = Tenant::factory()->create();
    $other->settings()->update(['contact_email' => 'theirs@example.com']);

    enterTenantPanel($own, Role::Admin);

    Livewire::test(Settings::class)
        ->assertFormSet(['contact_email' => 'ours@example.com']);
});

it('creates settings on first view if a tenant somehow has none', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings()->delete();
    enterTenantPanel($tenant, Role::Admin);

    Livewire::test(Settings::class)->assertOk();

    expect($tenant->refresh()->settings)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Writing settings
|--------------------------------------------------------------------------
*/

it('saves changes against the tenant in the panel', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Admin);

    Livewire::test(Settings::class)
        ->fillForm([
            'contact_email' => 'new@example.com',
            'contact_phone' => '+44 20 7946 0000',
            'accepts_orders' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = $tenant->refresh()->settings;

    expect($settings->contact_email)->toBe('new@example.com')
        ->and($settings->accepts_orders)->toBeFalse();
});

it('leaves other tenants settings alone when saving', function (): void {
    $own = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $other->settings()->update(['contact_email' => 'theirs@example.com']);

    enterTenantPanel($own, Role::Admin);

    Livewire::test(Settings::class)
        ->fillForm(['contact_email' => 'ours@example.com'])
        ->call('save');

    expect($other->refresh()->settings->contact_email)->toBe('theirs@example.com');
});

it('always prices in rupees, with no currency to choose', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Admin);

    Livewire::test(Settings::class)->assertFormFieldDoesNotExist('currency');

    expect($tenant->currency())->toBe(Currency::IndianRupee);
});

it('rejects an address that is not an email', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Admin);

    Livewire::test(Settings::class)
        ->fillForm(['contact_email' => 'not-an-email'])
        ->call('save')
        ->assertHasFormErrors(['contact_email' => 'email']);
});

/*
|--------------------------------------------------------------------------
| Who may see the page
|--------------------------------------------------------------------------
*/

it('is open to the tenant admin', function (): void {
    enterTenantPanel(Tenant::factory()->create(), Role::Admin);

    expect(Settings::canAccess())->toBeTrue();
});

it('is closed to floor staff', function (): void {
    enterTenantPanel(Tenant::factory()->create(), Role::Staff);

    expect(Settings::canAccess())->toBeFalse();
});

it('is open to a super admin supporting a tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->superAdmin()->create();

    $this->actingAs($user);
    Filament::setCurrentPanel(FilamentPanel::Tenant->value);
    Filament::setTenant($tenant);

    expect(Settings::canAccess())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Tax and charges
|--------------------------------------------------------------------------
|
| The GST every price on the menu is read against, plus the two optional
| charges. Each charge is a switch and an amount rather than an amount alone,
| so "we do not levy one" is something a tenant can say.
|
*/

it('starts a tenant on the standalone restaurant slab, tax added at the bill', function (): void {
    $tenant = Tenant::factory()->create();

    // The default in $attributes and TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS have to agree; a
    // property initialiser cannot call the static method, so this is what
    // keeps the two in step.
    expect($tenant->settings->taxRateBasisPoints())->toBe(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS)
        ->and($tenant->taxRateBasisPoints())->toBe(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS)
        ->and($tenant->settings->prices_include_tax)->toBeFalse()
        ->and($tenant->settings->service_charge_enabled)->toBeFalse()
        ->and($tenant->settings->parcel_charge_enabled)->toBeFalse();
});

it('saves the GST rate and whether prices already include it', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Admin);

    Livewire::test(Settings::class)
        ->fillForm([
            'gstin' => '29ABCDE1234F1Z5',
            // Typed as the percentage an accountant quotes, stored as basis
            // points — 18% is 1800.
            'tax_rate_percentage' => '18',
            'prices_include_tax' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = $tenant->refresh()->settings;

    expect($settings->gstin)->toBe('29ABCDE1234F1Z5')
        ->and($settings->taxRateBasisPoints())->toBe(1800)
        ->and($settings->prices_include_tax)->toBeTrue();
});

it('types a service charge as a percentage and stores it as basis points', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Admin);

    Livewire::test(Settings::class)
        ->fillForm([
            'service_charge_enabled' => true,
            'service_charge_percentage' => '10',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = $tenant->refresh()->settings;

    // Basis points, like a tax rate, so the arithmetic behind a bill stays in
    // integers: 10% of ₹500.00 is exactly ₹50.00.
    expect($settings->service_charge_basis_points)->toBe(1000)
        ->and($settings->serviceChargeOn(50000))->toBe(5000);
});

it('types a parcel charge as money and stores it in minor units', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Admin);

    Livewire::test(Settings::class)
        ->fillForm([
            'parcel_charge_enabled' => true,
            'parcel_charge' => '20.50',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = $tenant->refresh()->settings;

    expect($settings->parcel_charge_minor_units)->toBe(2050)
        ->and($settings->parcel_charge_minor_units)->toBeInt()
        ->and($settings->parcelCharge())->toBe(2050);
});

it('charges nothing while a charge is switched off, whatever its amount says', function (): void {
    // TenantFactory already gives every tenant its one settings row.
    $tenant = Tenant::factory()->create();
    $settings = $tenant->settings;

    $settings->update([
        'service_charge_enabled' => false,
        'service_charge_basis_points' => 1000,
        'parcel_charge_enabled' => false,
        'parcel_charge_minor_units' => 2000,
    ]);

    // The switch is what decides, not the number beside it — turning a charge
    // off must not mean losing the rate a tenant had set.
    expect($settings->serviceChargeOn(50000))->toBe(0)
        ->and($settings->parcelCharge())->toBe(0)
        ->and($settings->service_charge_basis_points)->toBe(1000)
        ->and($settings->parcel_charge_minor_units)->toBe(2000);
});

it('accepts a GST rate no fixed list of slabs would have held', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Admin);

    // India's GST 2.0 reform of September 2025 restructured the slabs; a rate
    // is typed rather than picked so the next notification is a number, not a
    // deployment.
    Livewire::test(Settings::class)
        ->fillForm(['tax_rate_percentage' => '12.5'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->settings->tax_rate_basis_points)->toBe(1250);
});

it('round-trips the GST rate through the form without drift', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings->update(['tax_rate_basis_points' => 1250]);

    enterTenantPanel($tenant, Role::Admin);

    Livewire::test(Settings::class)
        ->assertFormSet(['tax_rate_percentage' => 12.5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->settings->tax_rate_basis_points)->toBe(1250);
});

it('round-trips a service charge through the form without drift', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings->update(['service_charge_enabled' => true, 'service_charge_basis_points' => 250]);

    enterTenantPanel($tenant, Role::Admin);

    Livewire::test(Settings::class)
        ->assertFormSet(['service_charge_percentage' => 2.5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->settings->service_charge_basis_points)->toBe(250);
});
