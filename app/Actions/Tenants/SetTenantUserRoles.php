<?php

namespace App\Actions\Restaurants;

use App\Enums\Role as RoleEnum;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;

/**
 * Set the roles of someone on a restaurant's roster.
 *
 * This is the only path a restaurant panel takes to a user's roles, and it
 * holds the rules that make that safe.
 */
class SetRestaurantUserRoles
{
    public function __construct(private readonly EnsureRoleFitsWithinLimit $ensureRoleFits) {}

    /**
     * @param  list<string>  $roleNames
     */
    public function __invoke(User $user, array $roleNames): void
    {
        // Roles are held per account rather than per restaurant, so setting
        // them for someone who staffs more than one restaurant would change
        // what they can do at the others. That stays a super admin's call.
        if ($user->staffsSeveralRestaurants()) {
            return;
        }

        // Only roles a restaurant may hand out, whatever arrived in the form:
        // a role carrying a product team permission would mint the product team.
        $assignable = Role::query()
            ->assignableWithinRestaurant()
            ->whereIn('name', $roleNames)
            ->get();

        // A brand new account, mid-way through being added to its first
        // restaurant, has none yet — nothing to check the grant against.
        $restaurant = $user->restaurants()->first();

        if ($restaurant instanceof Restaurant) {
            foreach ($assignable as $role) {
                $roleEnum = RoleEnum::tryFrom($role->name);

                if ($roleEnum instanceof RoleEnum) {
                    ($this->ensureRoleFits)($restaurant, $roleEnum, $user);
                }
            }
        }

        // The models already loaded above, so Spatie does not look each one up
        // by name a second time.
        $user->syncRoles($assignable);
    }
}
