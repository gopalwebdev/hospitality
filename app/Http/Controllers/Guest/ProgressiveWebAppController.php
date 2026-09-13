<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * What makes the guest app installable.
 *
 * Both are served per tenant rather than as static files, because the name
 * on the home screen is the tenant's own — a phone with two tenants
 * installed shows two apps, not one twice — and both live at the root of the
 * tenant's subdomain, which is what lets the worker's scope be the whole app.
 */
class ProgressiveWebAppController extends Controller
{
    /**
     * The splash and status bar colour of the installed app.
     *
     * Fixed rather than per tenant: theming is light or dark and nothing
     * else, and a manifest colour is baked in at install time anyway.
     */
    private const string THEME_COLOR = '#E11D48';

    public function manifest(Tenant $tenant): JsonResponse
    {
        abort_unless($tenant->is_active, 404);

        return response()->json([
            'id' => '/',
            'name' => $tenant->name,
            'short_name' => $tenant->name,
            'description' => 'The menu at '.$tenant->name.'.',
            'start_url' => '/',
            'scope' => '/',
            // Standalone is the point of installing: no browser chrome.
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => self::THEME_COLOR,
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * A deliberately thin service worker.
     *
     * It makes the app installable and puts the last page a guest saw back up
     * when the signal drops. It does not make the app work offline — there is no
     * offline requirement, and Inertia needs the server for every page — so:
     *
     * - Built assets are content-hashed and never change under the same name,
     *   so they are answered from the cache once fetched.
     * - A page navigation goes to the network first and falls back to the copy
     *   from the last time it succeeded.
     * - Inertia's own requests are never cached: the same URL answers HTML to a
     *   navigation and JSON to Inertia, and handing one to the other breaks both.
     */
    public function serviceWorker(Tenant $tenant): Response
    {
        abort_unless($tenant->is_active, 404);

        $cache = json_encode('guest-'.$tenant->slug.'-v1', JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $javascript = <<<JS
        const CACHE = {$cache};

        self.addEventListener('install', () => self.skipWaiting());

        self.addEventListener('activate', (event) => {
            event.waitUntil(
                caches.keys()
                    .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
                    .then(() => self.clients.claim()),
            );
        });

        const remember = (request, response) => {
            if (response.ok) {
                const copy = response.clone();
                caches.open(CACHE).then((cache) => cache.put(request, copy));
            }

            return response;
        };

        self.addEventListener('fetch', (event) => {
            const request = event.request;
            const url = new URL(request.url);

            if (request.method !== 'GET' || url.origin !== self.location.origin) {
                return;
            }

            if (url.pathname.startsWith('/build/')) {
                event.respondWith(
                    caches.match(request).then((hit) => hit ?? fetch(request).then((response) => remember(request, response))),
                );

                return;
            }

            if (request.mode === 'navigate') {
                event.respondWith(
                    fetch(request)
                        .then((response) => remember(request, response))
                        .catch(() => caches.match(request).then((hit) => hit ?? Response.error())),
                );
            }
        });
        JS;

        return response($javascript, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // A worker is checked for updates on every navigation; this keeps a
            // new version from waiting behind a cached copy of the old one.
            'Cache-Control' => 'no-cache',
        ]);
    }
}
