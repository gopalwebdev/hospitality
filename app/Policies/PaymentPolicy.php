<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Payment;
use App\Models\User;

/**
 * Who may read payments, record one, and reverse one.
 *
 * A payment is never edited or hard-deleted, only voided — update, delete and
 * deleteAny all answer false outright, the OrderPolicy shape. Voiding is its
 * own permission, deliberately separate from recording: reversing money
 * already taken is an owner's call that Staff is not granted.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PaymentView->value);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can(Permission::PaymentView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PaymentRecord->value);
    }

    /**
     * A payment is never edited once recorded.
     */
    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    /**
     * A payment is never hard-deleted, only voided.
     */
    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Reverse a payment already recorded.
     */
    public function void(User $user, Payment $payment): bool
    {
        return $user->can(Permission::PaymentVoid->value);
    }
}
