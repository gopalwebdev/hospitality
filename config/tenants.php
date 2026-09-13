<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Role Limits
    |--------------------------------------------------------------------------
    |
    | How many accounts may hold the admin and staff roles at a restaurant that
    | has not had its own limits set. A super admin edits the limits of any one
    | restaurant from its own record; these are only what a newly created
    | restaurant starts with. See App\Actions\Restaurants\EnsureRoleFitsWithinLimit.
    |
    */

    'default_max_admins' => (int) env('RESTAURANT_DEFAULT_MAX_ADMINS', 1),

    'default_max_staff' => (int) env('RESTAURANT_DEFAULT_MAX_STAFF', 5),

];
