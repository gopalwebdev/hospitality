<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\PlatformPanelProvider;
use App\Providers\Filament\TenantPanelProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    // First, and it matters: both panels are served at /dashboard, and the
    // tenant panel's sign-in route answers on any host. Registered before
    // it, the product team panel's root-domain routes are the ones the root
    // domain matches. PanelRoutingTest pins this.
    PlatformPanelProvider::class,
    TenantPanelProvider::class,
    HorizonServiceProvider::class,
];
