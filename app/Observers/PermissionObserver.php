<?php

namespace App\Observers;

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use LogicException;

/**
 * Keeps built-in permissions intact and one a role holds from being deleted.
 *
 * Wired in Permission::booting() for the same reason as RoleObserver.
 */
class PermissionObserver
{
    public function updating(Permission $permission): void
    {
        throw_if(
            $permission->isDirty('name') && PermissionEnum::tryFrom((string) $permission->getOriginal('name')) instanceof PermissionEnum,
            LogicException::class,
            'A built-in permission may not be renamed.',
        );
    }

    public function deleting(Permission $permission): void
    {
        throw_if($permission->isBuiltIn(), LogicException::class, 'A built-in permission may not be deleted.');
        throw_if($permission->roles()->exists(), LogicException::class, 'A permission held by a role may not be deleted.');
    }
}
