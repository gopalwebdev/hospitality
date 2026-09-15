<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;

/**
 * Who may read the orders guests place, and who may call one off.
 *
 * Reading is order.view-any and cancelling order.manage, both of which floor
 * staff hold as well as a tenant owner. Nobody creates, edits or deletes an order
 * here: guests place them from a menu (PlaceOrder), and what was ordered is a
 * record rather than a draft. Which tenant's orders are in front of you is not
 * this policy's business; Filament scopes the resource to the panel's tenant.
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
     * Orders are placed by guests from a menu, never typed in here.
     */
    public function create(User $user): bool
    {
        return false;
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
}
