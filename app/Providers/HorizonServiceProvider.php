<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register the Horizon gate.
     *
     * Queue contents span every tenant on the platform, so the dashboard
     * belongs to the product team rather than to any one tenant's admin.
     */
    protected function gate(): void
    {
        Gate::define(
            'viewHorizon',
            static fn (?User $user): bool => $user instanceof User && $user->isSuperAdmin(),
        );
    }
}
