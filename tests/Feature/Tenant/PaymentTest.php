<?php

use App\Actions\Orders\CancelOrder;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\VoidPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Exceptions\PaymentRefused;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentDevice;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
|
| Money staff actually took, and how it is split across the orders it
| settles. A payment is one real transaction — one card swipe, one UPI scan —
| and order_payments says which orders it cleared and by how much, so one
| swipe can settle a whole stay and one bill can be split across two methods.
|
| A payment is voided, never deleted.
|
*/

/**
 * A placed order of the given tenant, owing exactly this much.
 */
function orderOwing(Tenant $tenant, int $total): Order
{
    return Order::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'status' => OrderStatus::Placed,
        'subtotal' => $total,
        'tax' => 0,
        'cgst' => 0,
        'sgst' => 0,
        'charges_total' => 0,
        'total' => $total,
    ]);
}

it('records a payment against one order and settles it', function (): void {
    $tenant = Tenant::factory()->create();
    $order = orderOwing($tenant, 50000);

    $payment = app(RecordPayment::class)($tenant, PaymentMethod::Cash, 50000, [$order->getKey() => 50000]);

    expect($payment->tenant_id)->toBe($tenant->getKey())
        ->and($payment->amount)->toBe(50000)
        ->and($order->fresh()?->amountPaid())->toBe(50000)
        ->and($order->fresh()?->amountOutstanding())->toBe(0)
        ->and($order->fresh()?->paymentState())->toBe(PaymentState::Paid);
});

it('settles several orders with one payment', function (): void {
    $tenant = Tenant::factory()->create();
    $first = orderOwing($tenant, 90000);
    $second = orderOwing($tenant, 150000);

    // One card swipe at checkout clearing a whole stay: one payment row, one
    // transaction number, an allocation per order.
    $payment = app(RecordPayment::class)(
        $tenant,
        PaymentMethod::CreditCard,
        240000,
        [$first->getKey() => 90000, $second->getKey() => 150000],
        reference: 'APPROVAL-4471',
    );

    expect($payment->allocations()->count())->toBe(2)
        ->and($payment->reference)->toBe('APPROVAL-4471')
        ->and($first->fresh()?->paymentState())->toBe(PaymentState::Paid)
        ->and($second->fresh()?->paymentState())->toBe(PaymentState::Paid);
});

it('splits one order across two methods', function (): void {
    $tenant = Tenant::factory()->create();
    $order = orderOwing($tenant, 100000);

    app(RecordPayment::class)($tenant, PaymentMethod::Cash, 40000, [$order->getKey() => 40000]);

    expect($order->fresh()?->paymentState())->toBe(PaymentState::PartlyPaid)
        ->and($order->fresh()?->amountOutstanding())->toBe(60000);

    app(RecordPayment::class)($tenant, PaymentMethod::Upi, 60000, [$order->getKey() => 60000]);

    expect($order->fresh()?->amountPaid())->toBe(100000)
        ->and($order->fresh()?->paymentState())->toBe(PaymentState::Paid);
});

it('refuses to overpay an order', function (): void {
    $tenant = Tenant::factory()->create();
    $order = orderOwing($tenant, 50000);

    expect(fn () => app(RecordPayment::class)($tenant, PaymentMethod::Cash, 60000, [$order->getKey() => 60000]))
        ->toThrow(PaymentRefused::class);

    // Refused outright: nothing is written at all.
    expect(Payment::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($order->fresh()?->amountPaid())->toBe(0);
});

it('refuses allocations that do not add up to the payment', function (): void {
    $tenant = Tenant::factory()->create();
    $order = orderOwing($tenant, 50000);

    expect(fn () => app(RecordPayment::class)($tenant, PaymentMethod::Cash, 50000, [$order->getKey() => 40000]))
        ->toThrow(PaymentRefused::class);

    expect(Payment::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a cancelled order and an order of another tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $cancelled = orderOwing($tenant, 50000);
    app(CancelOrder::class)($cancelled);

    expect(fn () => app(RecordPayment::class)($tenant, PaymentMethod::Cash, 50000, [$cancelled->getKey() => 50000]))
        ->toThrow(PaymentRefused::class);

    $foreign = orderOwing(Tenant::factory()->create(), 50000);

    expect(fn () => app(RecordPayment::class)($tenant, PaymentMethod::Cash, 50000, [$foreign->getKey() => 50000]))
        ->toThrow(PaymentRefused::class);
});

it('refuses a device that does not fit the method', function (): void {
    $tenant = Tenant::factory()->create();
    $order = orderOwing($tenant, 50000);
    $qrCode = PaymentDevice::factory()->ofTenant($tenant)->qrCode()->create();

    // A card is not swiped through a QR code: PaymentMethod::deviceKind() is
    // the single place that pairing is stated.
    expect(fn () => app(RecordPayment::class)($tenant, PaymentMethod::CreditCard, 50000, [$order->getKey() => 50000], $qrCode))
        ->toThrow(PaymentRefused::class);

    $foreignMachine = PaymentDevice::factory()->cardMachine()->create();

    expect(fn () => app(RecordPayment::class)($tenant, PaymentMethod::CreditCard, 50000, [$order->getKey() => 50000], $foreignMachine))
        ->toThrow(PaymentRefused::class);
});

it('clears the device and reference cash does not carry', function (): void {
    $tenant = Tenant::factory()->create();
    $order = orderOwing($tenant, 50000);
    $machine = PaymentDevice::factory()->ofTenant($tenant)->cardMachine()->create();

    // Cleared rather than refused: a device handed in for cash is simply not
    // the point of cash, not a mistake worth stopping the payment for.
    $payment = app(RecordPayment::class)(
        $tenant,
        PaymentMethod::Cash,
        50000,
        [$order->getKey() => 50000],
        $machine,
        'TYPED-ANYWAY',
    );

    expect($payment->payment_device_id)->toBeNull()
        ->and($payment->reference)->toBeNull()
        ->and(PaymentMethod::Cash->takesReference())->toBeFalse()
        ->and(PaymentMethod::Cash->deviceKind())->toBeNull();
});

it('keeps the device a card was actually swiped on', function (): void {
    $tenant = Tenant::factory()->create();
    $order = orderOwing($tenant, 50000);
    $machine = PaymentDevice::factory()->ofTenant($tenant)->cardMachine()->create();
    $user = User::factory()->create();

    $payment = app(RecordPayment::class)(
        $tenant,
        PaymentMethod::DebitCard,
        50000,
        [$order->getKey() => 50000],
        $machine,
        'APPROVAL-99',
        recordedBy: $user,
    );

    expect($payment->payment_device_id)->toBe($machine->getKey())
        ->and($payment->reference)->toBe('APPROVAL-99')
        ->and($payment->recorded_by_user_id)->toBe($user->getKey());
});

it('gives the money back to the order when a payment is voided', function (): void {
    $tenant = Tenant::factory()->create();
    $order = orderOwing($tenant, 50000);
    $user = User::factory()->create();

    $payment = app(RecordPayment::class)($tenant, PaymentMethod::Cash, 50000, [$order->getKey() => 50000]);

    expect($order->fresh()?->paymentState())->toBe(PaymentState::Paid);

    app(VoidPayment::class)($payment, 'Charged to the wrong room', $user);

    // The allocations stay — the history is the point — but they are no
    // longer live money, so the order owes again.
    expect($payment->fresh()?->isVoided())->toBeTrue()
        ->and($payment->fresh()?->void_reason)->toBe('Charged to the wrong room')
        ->and($payment->fresh()?->voided_by_user_id)->toBe($user->getKey())
        ->and($payment->allocations()->count())->toBe(1)
        ->and($order->fresh()?->amountPaid())->toBe(0)
        ->and($order->fresh()?->paymentState())->toBe(PaymentState::Unpaid);
});

it('refuses to void a payment twice', function (): void {
    $tenant = Tenant::factory()->create();
    $order = orderOwing($tenant, 50000);
    $payment = app(RecordPayment::class)($tenant, PaymentMethod::Cash, 50000, [$order->getKey() => 50000]);

    app(VoidPayment::class)($payment);

    expect(fn () => app(VoidPayment::class)($payment->fresh()))->toThrow(LogicException::class);
});

it('refuses to cancel an order a payment still stands against', function (): void {
    $tenant = Tenant::factory()->create();
    $order = orderOwing($tenant, 50000);
    $payment = app(RecordPayment::class)($tenant, PaymentMethod::Cash, 50000, [$order->getKey() => 50000]);

    // Money first: staff void the payment before the order comes off, which
    // is the auditable order to do it in.
    expect(fn () => app(CancelOrder::class)($order))->toThrow(LogicException::class);

    app(VoidPayment::class)($payment);
    app(CancelOrder::class)($order->fresh());

    expect($order->fresh()?->status)->toBe(OrderStatus::Cancelled);
});

it('counts only live payments when reading what an order has paid', function (): void {
    $tenant = Tenant::factory()->create();
    $order = orderOwing($tenant, 90000);

    Payment::factory()->ofTenant($tenant)->settling($order)->voided()->create();

    // A loaded sum and a fresh query must agree, and both must ignore a
    // voided payment — the badge reads the loaded one.
    $loaded = Order::query()
        ->withoutGlobalScopes()
        ->whereKey($order->getKey())
        ->withSum(['paymentAllocations as amount_paid' => fn ($allocations) => $allocations
            ->whereHas('payment', fn ($payment) => $payment->live())], 'amount')
        ->sole();

    expect($loaded->amountPaid())->toBe(0)
        ->and($order->fresh()?->amountPaid())->toBe(0)
        ->and($order->fresh()?->paymentState())->toBe(PaymentState::Unpaid);
});
