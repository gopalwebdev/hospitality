<?php

namespace App\Exceptions;

use App\Enums\PaymentRefusal;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A payment refused before anything was written: RecordPayment's own checks
 * run entirely under its lock, before the payments row or any order_payments
 * row exists.
 *
 * Rendered as 422 like OrderRefused, with the order the refusal is about when
 * one order is why — a foreign or cancelled order, or an over-allocation.
 * AllocationMismatch and DeviceMismatch are about the payment as a whole, so
 * they carry no order.
 */
final class PaymentRefused extends RuntimeException
{
    public function __construct(public readonly PaymentRefusal $reason, public readonly ?int $orderId = null)
    {
        parent::__construct($reason->message());
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->reason->message(),
            'reason' => $this->reason->value,
            'orderId' => $this->orderId,
        ], 422);
    }
}
