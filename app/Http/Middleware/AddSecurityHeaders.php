<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers every surface carries: both panels, the guest app and the welcome page.
 *
 * Registered globally rather than on the `web` group, because a Filament panel
 * does not run that group — the same reason `SetLocale` is listed on each
 * panel. A header set in one place cannot be forgotten on a page added later.
 *
 * What is deliberately **not** here is a Content-Security-Policy. Filament and
 * Livewire both inline scripts and styles, as does Vite in development, so a
 * real policy needs per-request nonces threaded through every render hook and
 * Blade layout. A policy loose enough to skip that (`unsafe-inline`,
 * `unsafe-eval`) buys nothing but a header that looks reassuring. It is worth
 * doing properly and separately; it is not worth faking here.
 */
class AddSecurityHeaders
{
    /**
     * A year, which is the minimum any HSTS preload list accepts.
     */
    private const int STRICT_TRANSPORT_MAX_AGE = 31_536_000;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // SAMEORIGIN rather than DENY: the guest app embeds a tenant's uploaded
        // PDF in an iframe of its own origin (pages/guest/document.tsx), and
        // DENY would blank it. Nothing here is meant to be framed by anyone else.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // An uploaded file is served back under the type it was stored with;
        // this stops a browser guessing something more dangerous from content.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // A tenant's subdomain is its own name. Full URLs stay inside the
        // origin and only the origin leaves it.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Nothing in either surface asks for these, so nothing may.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // Only over TLS: sending it over plain HTTP is meaningless, and in
        // local development it would pin a browser to https for the whole
        // .test domain long after the work is finished.
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.self::STRICT_TRANSPORT_MAX_AGE.'; includeSubDomains',
            );
        }

        return $response;
    }
}
