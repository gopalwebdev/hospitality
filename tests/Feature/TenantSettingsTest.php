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
use Illuminate\Support\Facades\Schema;
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
    enterTenantPanel($tenant, Role::Owner);

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

    enterTenantPanel($own, Role::Owner);

    Livewire::test(Settings::class)
        ->assertFormSet(['contact_email' => 'ours@example.com']);
});

it('creates settings on first view if a tenant somehow has none', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings()->delete();
    enterTenantPanel($tenant, Role::Owner);

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
    enterTenantPanel($tenant, Role::Owner);

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

    enterTenantPanel($own, Role::Owner);

    Livewire::test(Settings::class)
        ->fillForm(['contact_email' => 'ours@example.com'])
        ->call('save');

    expect($other->refresh()->settings->contact_email)->toBe('theirs@example.com');
});

it('always prices in rupees, with no currency to choose', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Owner);

    Livewire::test(Settings::class)->assertFormFieldDoesNotExist('currency');

    expect($tenant->currency())->toBe(Currency::IndianRupee);
});

it('rejects an address that is not an email', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Owner);

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

it('is open to the tenant owner', function (): void {
    enterTenantPanel(Tenant::factory()->create(), Role::Owner);

    expect(Settings::canAccess())->toBeTrue();
});

it('is closed to floor staff', function (): void {
    enterTenantPanel(Tenant::factory()->create(), Role::Staff);

    expect(Settings::canAccess())->toBeFalse();
});

it('is open to an admin supporting a tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->admin()->create();

    $this->actingAs($user);
    Filament::setCurrentPanel(FilamentPanel::Tenant->value);
    Filament::setTenant($tenant);

    expect(Settings::canAccess())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Tax
|--------------------------------------------------------------------------
|
| The GST every price on the menu is read against. What is added on top of a
| bill is not a setting any more: charges have their own page, and their tests
| are in tests/Feature/Tenant/ChargeManagementTest.php.
|
*/

it('starts a tenant on the default GST slab, tax added at the bill', function (): void {
    $tenant = Tenant::factory()->create();

    // The default in $attributes and TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS have to agree; a
    // property initialiser cannot call the static method, so this is what
    // keeps the two in step.
    expect($tenant->settings->taxRateBasisPoints())->toBe(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS)
        ->and($tenant->taxRateBasisPoints())->toBe(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS)
        ->and($tenant->settings->prices_include_tax)->toBeFalse();
});

it('keeps no charges among the settings', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Owner);

    // Two fixed switches could say a service charge and a packing charge and
    // nothing else, and could not say which menus either belonged on.
    Livewire::test(Settings::class)
        ->assertFormFieldDoesNotExist('service_charge_percentage')
        ->assertFormFieldDoesNotExist('parcel_charge');

    expect(Schema::hasColumn('tenant_settings', 'service_charge_basis_points'))->toBeFalse()
        ->and(Schema::hasColumn('tenant_settings', 'parcel_charge_minor_units'))->toBeFalse();
});

it('saves the GST rate and whether prices already include it', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Owner);

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

it('accepts a GST rate no fixed list of slabs would have held', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Owner);

    // India's GST 2.0 reform of September 2025 restructured the slabs; a rate
    // is typed rather than picked so the next notification is a number, not a
    // deployment.
    Livewire::test(Settings::class)
        ->fillForm(['tax_rate_percentage' => '12.5'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->settings->tax_rate_basis_points)->toBe(1250);
});

it('keeps opening hours as the clock picker sets them, and opens the form with them', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Owner);

    Livewire::test(Settings::class)
        ->fillForm(['opens_at' => '09:30', 'closes_at' => '23:00'])
        ->call('save')
        ->assertHasNoFormErrors();

    // A time column hands the seconds back; the picker is filled with hours and
    // minutes, which is what it shows.
    Livewire::test(Settings::class)
        ->assertFormSet(['opens_at' => '09:30', 'closes_at' => '23:00'])
        ->assertSeeHtml('clock-picker');
});

it('round-trips the GST rate through the form without drift', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings->update(['tax_rate_basis_points' => 1250]);

    enterTenantPanel($tenant, Role::Owner);

    Livewire::test(Settings::class)
        ->assertFormSet(['tax_rate_percentage' => 12.5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->settings->tax_rate_basis_points)->toBe(1250);
});
