<?php

namespace App\Observers;

use App\Enums\Role as RoleEnum;
use App\Models\Role;
use LogicException;

/**
 * Keeps built-in roles intact and a role in use from being deleted.
 *
 * Wired in Role::booting() rather than with #[ObservedBy]: Spatie detaches a
 * role's users and permissions in its own deleting listener, and an observer
 * attached after boot would ask about links already cut.
 */
class RoleObserver
{
    public function updating(Role $role): void
    {
        throw_if(
            $role->isDirty('name') && RoleEnum::tryFrom((string) $role->getOriginal('name')) instanceof RoleEnum,
            LogicException::class,
            'A built-in role may not be renamed.',
        );
    }

    /**
     * Asks the database, never a loaded count: a count decides what a page
     * shows, not what may be destroyed.
     */
    public function deleting(Role $role): void
    {
        throw_if($role->isBuiltIn(), LogicException::class, 'A built-in role may not be deleted.');
        throw_if($role->users()->exists(), LogicException::class, 'A role held by a user may not be deleted.');
        throw_if($role->permissions()->exists(), LogicException::class, 'A role holding permissions may not be deleted.');
    }
}
