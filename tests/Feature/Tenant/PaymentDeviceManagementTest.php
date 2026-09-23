<?php

use App\Enums\PaymentDeviceKind;
use App\Enums\PaymentMethod;
use App\Enums\Role as RoleEnum;
use App\Filament\Tenant\Resources\PaymentDevices\Pages\ListPaymentDevices;
use App\Models\PaymentDevice;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Payment devices
|--------------------------------------------------------------------------
|
| The card machines and QR codes a tenant takes money on. A tenant may have
| several of each, and a payment names the one it went through so the day's
| takings can be reconciled machine by machine.
|
| Their names are staff-facing and deliberately not translated: no guest ever
| reads "Counter machine 1".
|
*/

it('creates a card machine on the tenant in the panel', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListPaymentDevices::class)
        ->callAction('create', [
            'kind' => PaymentDeviceKind::CardMachine->value,
            'name' => 'Counter machine 1',
            'identifier' => 'TERM-0099',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $device = PaymentDevice::query()->withoutGlobalScopes()->sole();

    expect($device->tenant_id)->toBe($tenant->getKey())
        ->and($device->kind)->toBe(PaymentDeviceKind::CardMachine)
        ->and($device->name)->toBe('Counter machine 1')
        ->and($device->identifier)->toBe('TERM-0099');
});

it('offers a method only the devices it can actually go through', function (): void {
    $tenant = Tenant::factory()->create();
    $machine = PaymentDevice::factory()->ofTenant($tenant)->cardMachine()->create();
    $qrCode = PaymentDevice::factory()->ofTenant($tenant)->qrCode()->create();

    $forCard = PaymentDevice::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->forMethod(PaymentMethod::CreditCard)
        ->pluck('id')
        ->all();

    $forUpi = PaymentDevice::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->forMethod(PaymentMethod::Upi)
        ->pluck('id')
        ->all();

    // PaymentMethod::deviceKind() is the single place this pairing is stated.
    expect($forCard)->toBe([$machine->getKey()])
        ->and($forUpi)->toBe([$qrCode->getKey()])
        ->and(PaymentMethod::CreditCard->deviceKind())->toBe(PaymentDeviceKind::CardMachine)
        ->and(PaymentMethod::Upi->deviceKind())->toBe(PaymentDeviceKind::QrCode);
});

it('sees only its own tenant\'s devices', function (): void {
    $tenant = Tenant::factory()->create();
    $ours = PaymentDevice::factory()->ofTenant($tenant)->cardMachine()->create();
    $theirs = PaymentDevice::factory()->cardMachine()->create();

    enterTenantPanel($tenant, RoleEnum::Owner);

    Livewire::test(ListPaymentDevices::class)
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('drags devices into the order staff read them', function (): void {
    $tenant = Tenant::factory()->create();
    $first = PaymentDevice::factory()->ofTenant($tenant)->cardMachine()->create(['position' => 0]);
    $second = PaymentDevice::factory()->ofTenant($tenant)->qrCode()->create(['position' => 1]);

    enterTenantPanel($tenant, RoleEnum::Owner);

    // reorderTable() short-circuits on the reorder() policy method, so calling
    // it for real is the only thing that proves the drag works.
    Livewire::test(ListPaymentDevices::class)
        ->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($first->fresh()?->position)->toBe(2)
        ->and($second->fresh()?->position)->toBe(1);
});

it('refuses the page to floor staff, who do not configure a tenant', function (): void {
    $tenant = Tenant::factory()->create();
    enterTenantPanel($tenant, RoleEnum::Staff);

    // Staff take payments; they do not add machines. That is settings.manage.
    Livewire::test(ListPaymentDevices::class)->assertForbidden();
});
