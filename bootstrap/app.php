<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel Cloud serves traffic through a load balancer that terminates
        // TLS, so the forwarded headers must be trusted for correct HTTPS URLs.
        $middleware->trustProxies(at: '*');

        // Both are read by JavaScript as well as by PHP — the theme toggle and
        // the language toggle each need to know what is currently set before
        // the server can tell them — so neither may be encrypted.
        $middleware->encryptCookies(except: ['appearance', 'locale']);

        // Global, not on the `web` group: a Filament panel does not run that
        // group, and these have to hold on every surface. See the middleware
        // for why there is no CSP among them.
        $middleware->append(AddSecurityHeaders::class);

        // SetLocale comes first: everything after it, the Inertia middleware
        // included, renders in the language it chooses.
        $middleware->web(append: [
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
