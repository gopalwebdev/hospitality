<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Role Limits
    |--------------------------------------------------------------------------
    |
    | How many accounts may hold the admin and staff roles at a tenant that
    | has not had its own limits set. A super admin edits the limits of any one
    | tenant from its own record; these are only what a newly created
    | tenant starts with. See App\Actions\Tenants\EnsureRoleFitsWithinLimit.
    |
    */

    'default_max_admins' => (int) env('TENANT_DEFAULT_MAX_ADMINS', 1),

    'default_max_staff' => (int) env('TENANT_DEFAULT_MAX_STAFF', 5),

];
