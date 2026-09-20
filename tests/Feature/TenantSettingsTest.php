<?php

use App\Enums\Currency;
use App\Enums\FilamentPanel;
use App\Enums\Role;
use App\Enums\Weekday;
use App\Filament\Tenant\Pages\Settings;
use App\Models\Tenant;
use App\Models\TenantOpeningHour;
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
            'alternate_phone' => '+91 98765 43210',
            'landline_phone' => '+91 44 2345 6789',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = $tenant->refresh()->settings;

    expect($settings->contact_email)->toBe('new@example.com')
        ->and($settings->contact_phone)->toBe('+44 20 7946 0000')
        // Three numbers, because a tenant is reached on more than one.
        ->and($settings->alternate_phone)->toBe('+91 98765 43210')
        ->and($settings->landline_phone)->toBe('+91 44 2345 6789');
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
    expect($tenant->settings->taxRate())->toBe(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS)
        ->and($tenant->taxRate())->toBe(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS)
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

it('saves GST as the two halves it is levied in, and whether prices already include it', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Owner);

    Livewire::test(Settings::class)
        ->fillForm([
            'gstin' => '29ABCDE1234F1Z5',
            // Typed as the percentages an accountant quotes, stored as basis
            // points — 9% each is 900, and 18% in all.
            'cgst_rate_percentage' => '9',
            'sgst_rate_percentage' => '9',
            'prices_include_tax' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = $tenant->refresh()->settings;

    expect($settings->gstin)->toBe('29ABCDE1234F1Z5')
        ->and($settings->cgst_rate)->toBe(900)
        ->and($settings->sgst_rate)->toBe(900)
        // What anything is actually taxed at is the two added up.
        ->and($settings->taxRate())->toBe(1800)
        ->and($settings->prices_include_tax)->toBeTrue();
});

it('charges its own rate on every item when told to override them', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Owner);

    expect($tenant->overridesItemTaxRates())->toBeFalse();

    Livewire::test(Settings::class)
        ->fillForm(['tax_overrides_item_rates' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->overridesItemTaxRates())->toBeTrue();
});

it('accepts a GST rate no fixed list of slabs would have held', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Owner);

    // India's GST 2.0 reform of September 2025 restructured the slabs; a rate
    // is typed rather than picked so the next notification is a number, not a
    // deployment.
    Livewire::test(Settings::class)
        ->fillForm(['cgst_rate_percentage' => '6.25', 'sgst_rate_percentage' => '6.25'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->settings->taxRate())->toBe(1250);
});

it('round-trips the GST halves through the form without drift', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->settings->update(['cgst_rate' => 625, 'sgst_rate' => 625]);

    enterTenantPanel($tenant, Role::Owner);

    Livewire::test(Settings::class)
        ->assertFormSet(['cgst_rate_percentage' => 6.25, 'sgst_rate_percentage' => 6.25])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->settings->taxRate())->toBe(1250);
});

/*
|--------------------------------------------------------------------------
| The week the doors keep
|--------------------------------------------------------------------------
|
| Hours repeat weekly: a row per day, open between two times or closed for
| the day. What those hours mean — whether the doors are open right now — is
| Tenant::isOpenAt(), tested in tests/Feature/Tenant/StoreHoursTest.php.
|
*/

it('opens the week with every day offered open until a tenant says otherwise', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Owner);

    Livewire::test(Settings::class)
        ->assertFormSet([
            'hours' => collect(Weekday::week())
                ->mapWithKeys(fn (Weekday $weekday): array => [
                    $weekday->value => ['is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '23:00'],
                ])
                ->all(),
        ])
        ->assertSeeHtml('clock-picker');
});

it('keeps a row per day of the week, and forgets the hours of a day it is closed', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, Role::Owner);

    $hours = collect(Weekday::week())
        ->mapWithKeys(fn (Weekday $weekday): array => [
            $weekday->value => ['is_closed' => false, 'opens_at' => '09:30', 'closes_at' => '23:00'],
        ])
        ->put(Weekday::Monday->value, ['is_closed' => true, 'opens_at' => '09:30', 'closes_at' => '23:00'])
        ->all();

    Livewire::test(Settings::class)
        ->fillForm(['hours' => $hours])
        ->call('save')
        ->assertHasNoFormErrors();

    $week = $tenant->refresh()->resolvedOpeningHours();

    expect($week)->toHaveCount(7)
        ->and($week->get(Weekday::Tuesday->value)->opensAt())->toBe('09:30')
        ->and($week->get(Weekday::Tuesday->value)->closesAt())->toBe('23:00')
        // A holiday keeps no hours: there are none to keep.
        ->and($week->get(Weekday::Monday->value)->is_closed)->toBeTrue()
        ->and($week->get(Weekday::Monday->value)->opens_at)->toBeNull()
        ->and($week->get(Weekday::Monday->value)->closes_at)->toBeNull();
});

it('edits the week it already keeps rather than adding a second one', function (): void {
    $tenant = Tenant::factory()->create();
    TenantOpeningHour::factory()->ofTenant($tenant)->on(Weekday::Friday)->between('08:00', '20:00')->create();

    enterTenantPanel($tenant, Role::Owner);

    Livewire::test(Settings::class)
        ->assertFormSet(['hours.friday.opens_at' => '08:00', 'hours.friday.closes_at' => '20:00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->openingHours()->count())->toBe(7)
        ->and($tenant->openingHours()->where('weekday', Weekday::Friday)->count())->toBe(1);
});
