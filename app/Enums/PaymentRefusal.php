<?php

namespace App\Enums;

/**
 * Why a payment was refused before anything was written. Nothing is written for any of them.
 *
 * The App\Enums\OrderRefusal shape one level up: RecordPayment throws
 * App\Exceptions\PaymentRefused with one of these, and it renders 422 with the
 * reason beside a message that may be shown as it is.
 */
enum PaymentRefusal: string
{
    /** The order named is not this tenant's own. */
    case ForeignOrder = 'foreign-order';

    /** The order named has already been cancelled. */
    case OrderCancelled = 'order-cancelled';

    /** An allocation asks for more than that order still owes. */
    case OverAllocated = 'over-allocated';

    /** The allocations are not all positive, or do not add up to the amount paid. */
    case AllocationMismatch = 'allocation-mismatch';

    /** The device named is not this tenant's, is inactive, or does not take this method. */
    case DeviceMismatch = 'device-mismatch';

    public function message(): string
    {
        return match ($this) {
            self::ForeignOrder => 'That order does not belong to this tenant.',
            self::OrderCancelled => 'A cancelled order cannot be paid.',
            self::OverAllocated => 'That allocation is more than the order still owes.',
            self::AllocationMismatch => 'The allocations must be positive and add up to the amount paid.',
            self::DeviceMismatch => 'That device cannot take this method of payment.',
        };
    }
}
