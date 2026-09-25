<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;

/**
 * Who may read the orders guests place, and who may call one off.
 *
 * Reading is order.view-any and cancelling order.manage, both of which floor
 * staff hold as well as a tenant owner. Creating is order.create, which staff
 * hold because they take orders at the counter and over the phone from the
 * panel's own Take order page — the same PlaceOrder a guest's phone calls.
 * Nothing is edited or deleted: what was ordered is a record rather than a
 * draft, and an order that should not stand is cancelled. Which tenant's
 * orders are in front of you is not this policy's business; Filament scopes
 * the resource to the panel's tenant.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::OrderViewAny->value);
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can(Permission::OrderViewAny->value);
    }

    /**
     * Take an order on a guest's behalf, from the panel's Take order page.
     *
     * This answered false while ordering was the guest app's alone. It is the
     * one thing a member of staff may now add here; an order still cannot be
     * edited or deleted once it stands.
     */
    public function create(User $user): bool
    {
        return $user->can(Permission::OrderCreate->value);
    }

    public function update(User $user, Order $order): bool
    {
        return false;
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Call an order off, putting back what it took from stock.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $user->can(Permission::OrderManage->value);
    }

    /**
     * Pick an order up: the kitchen has it, and its lines are fixed from here.
     */
    public function accept(User $user, Order $order): bool
    {
        return $user->can(Permission::OrderManage->value);
    }

    /**
     * Change what is on an order nobody has picked up yet.
     *
     * `order.manage` rather than `order.create`, though it ends in the same
     * basket: creating is bringing a new order into existence, and this is
     * working one that already exists — the same side of the line as accepting
     * and cancelling. Whether this particular order may still be changed is
     * Order::canBeChanged()'s answer and ReviseOrder's to enforce; this only
     * says who is allowed to try.
     */
    public function change(User $user, Order $order): bool
    {
        return $user->can(Permission::OrderManage->value);
    }

    /**
     * Record a payment against this order.
     */
    public function recordPayment(User $user, Order $order): bool
    {
        return $user->can(Permission::PaymentRecord->value);
    }
}
