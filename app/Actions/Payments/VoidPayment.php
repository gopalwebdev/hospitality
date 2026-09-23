<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Reverse a payment already recorded.
 *
 * Allocations are left exactly as they were — the history is the point, and
 * Order::amountPaid() and the settled/unsettled scopes already ignore a voided
 * payment, so voiding brings every order it touched straight back to what it
 * still owes without anything else being rewritten.
 */
final readonly class VoidPayment
{
    /**
     * @throws LogicException when the payment has already been voided
     */
    public function __invoke(Payment $payment, ?string $reason = null, ?User $user = null): void
    {
        DB::transaction(function () use ($payment, $reason, $user): void {
            $locked = Payment::query()
                ->withoutGlobalScopes()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail(['id', 'tenant_id', 'voided_at']);

            throw_if($locked->isVoided(), LogicException::class, 'This payment has already been voided.');

            $locked->update([
                'voided_at' => now(),
                'voided_by_user_id' => $user?->getKey(),
                'void_reason' => $reason,
            ]);
        });
    }
}
