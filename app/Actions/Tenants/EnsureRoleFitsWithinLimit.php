<?php

namespace App\Actions\Restaurants;

use App\Enums\Role as RoleEnum;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Refuse a role grant that would put a restaurant over its own limit.
 *
 * A restaurant may hold at most Restaurant::$max_admins admins and
 * Restaurant::$max_staff staff on its roster at once; a super admin sets both
 * numbers from the restaurant's own record (see RestaurantForm). This is the
 * one place every path that can grant a capped role checks before the grant
 * is written, whichever panel it comes from: SetRestaurantUserRoles (and
 * AddUserToRestaurant, which calls it) from the tenant panel, and
 * SetUserRoles from the product team panel — so the limit cannot be worked
 * around by going through a different door.
 *
 * The message is keyed 'data.roles' rather than 'roles': every page that
 * calls this puts its form at Filament's default statePath, 'data', and
 * Livewire only binds a validation error to a field whose wire:model matches
 * the key exactly.
 */
class EnsureRoleFitsWithinLimit
{
    /**
     * @throws ValidationException when $restaurant already holds as many
     *                             $role holders as its limit allows, not counting $user itself
     */
    public function __invoke(Restaurant $restaurant, RoleEnum $role, User $user): void
    {
        $limit = $restaurant->roleLimit($role);

        if ($limit === null) {
            return;
        }

        $current = $restaurant->roleHolderCount($role, excluding: $user);

        if ($current < $limit) {
            return;
        }

        throw ValidationException::withMessages([
            'data.roles' => sprintf(
                '%s already has %d of %d allowed %s.',
                $restaurant->name,
                $current,
                $limit,
                $this->roleLabel($role, $current),
            ),
        ]);
    }

    /**
     * "admin" reads oddly pluralised next to a count, so this spells out the
     * plain English word for each capped role rather than leaning on
     * Str::plural().
     */
    private function roleLabel(RoleEnum $role, int $count): string
    {
        return match ($role) {
            RoleEnum::Admin => $count === 1 ? 'admin' : 'admins',
            RoleEnum::Staff => $count === 1 ? 'staff member' : 'staff members',
            RoleEnum::Guest => $role->value,
        };
    }
}
