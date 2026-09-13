<?php

namespace App\Observers;

use App\Models\User;
use LogicException;

class UserObserver
{
    /**
     * An account belongs to a tenant or to the product team, never both: the
     * product team holds every permission on every tenant.
     */
    public function saving(User $user): void
    {
        throw_if(
            $user->is_admin && $user->tenant_id !== null,
            LogicException::class,
            'An account that belongs to a tenant may not be on the product team.',
        );
    }
}
