<?php

namespace App\Actions\Restaurants;

use App\Models\Restaurant;
use App\Models\User;

/**
 * Take someone off a restaurant's roster.
 *
 * The account itself is left alone: it is platform-wide, may staff other
 * restaurants, and a restaurant panel has no business deleting it. Someone
 * detached from their last restaurant keeps their account and simply has no
 * panel to enter, because User::canAccessPanel() finds no restaurant.
 */
class RemoveUserFromRestaurant
{
    public function __invoke(Restaurant $restaurant, User $user): void
    {
        $restaurant->users()->detach($user->getKey());
    }
}
