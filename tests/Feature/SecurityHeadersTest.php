<?php

use App\Models\Tenant;

/*
|--------------------------------------------------------------------------
| The headers every surface carries
|--------------------------------------------------------------------------
|
| AddSecurityHeaders is registered globally rather than on the `web` group,
| because a Filament panel does not run that group. These tests are what stop
| a page added later from quietly going out without them.
|
*/

it('carries the security headers on the guest app', function (): void {
    $tenant = Tenant::factory()->create();

    $this->get('http://'.$tenant->slug.'.hospitality.test/')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
});

it('carries them on a panel too, which does not run the web group', function (): void {
    // The one that would be missed: Filament builds its own middleware stack,
    // so a header added to `web` alone would cover the guest app and neither panel.
    $this->get('http://hospitality.test/dashboard/login')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('frames nothing from another origin, but lets the guest app embed its own PDF', function (): void {
    $tenant = Tenant::factory()->create();

    // SAMEORIGIN rather than DENY is deliberate: pages/guest/document.tsx
    // embeds a tenant's uploaded PDF in an iframe of this same origin, and
    // DENY would blank it.
    $this->get('http://'.$tenant->slug.'.hospitality.test/')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

it('asks for HTTPS only when it is already being spoken', function (): void {
    $tenant = Tenant::factory()->create();

    // Over plain HTTP the header means nothing, and locally it would pin the
    // browser to https for the whole .test domain long after this work.
    $this->get('http://'.$tenant->slug.'.hospitality.test/')
        ->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://'.$tenant->slug.'.hospitality.test/')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
