<?php

namespace App\Http\Controllers;

use App\Enums\FilamentPanel;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * What makes a panel installable, on the root domain and on every tenant's subdomain.
 *
 * Both files sit under the panel's own path, never at the root of the host. A
 * tenant's subdomain also serves the guest app, whose worker is scoped to the
 * whole subdomain: a panel worker at the root would replace it, while one
 * scoped to /dashboard takes only the panel's pages.
 */
class PanelProgressiveWebAppController extends Controller
{
    public function platformManifest(): JsonResponse
    {
        return $this->manifest(FilamentPanel::Platform, FilamentPanel::Platform->brandName());
    }

    /**
     * Named apart from the guest app on the same subdomain, which installs under
     * the tenant's bare name. A switched-off tenant's panel still opens, so it
     * is still installable.
     */
    public function tenantManifest(Tenant $tenant): JsonResponse
    {
        return $this->manifest(FilamentPanel::Tenant, $tenant->name.' Dashboard');
    }

    public function platformServiceWorker(): Response
    {
        return $this->serviceWorker(FilamentPanel::Platform);
    }

    /**
     * The tenant is bound so that a subdomain with no tenant behind it is a 404.
     */
    public function tenantServiceWorker(Tenant $tenant): Response
    {
        return $this->serviceWorker(FilamentPanel::Tenant);
    }

    private function manifest(FilamentPanel $panel, string $name): JsonResponse
    {
        $scope = '/'.$panel->path();

        return response()->json([
            'id' => $scope,
            'name' => $name,
            'short_name' => $name,
            'start_url' => $scope,
            'scope' => $scope,
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => $panel->themeColor(),
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * A worker that does nothing but exist: no fetch handler and no cache.
     *
     * A panel page is a signed-in Livewire page carrying a CSRF token, and an old
     * copy of one is worse than the browser's own offline page. Scoped to the
     * panel, it also takes the panel's pages away from the guest app's worker,
     * which would otherwise keep copies of them in a browser that opened both.
     */
    private function serviceWorker(FilamentPanel $panel): Response
    {
        $javascript = <<<'JS'
        self.addEventListener('install', () => self.skipWaiting());
        self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
        JS;

        return response($javascript, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // A worker is checked for updates on every navigation; this keeps a
            // new version from waiting behind a cached copy of the old one.
            'Cache-Control' => 'no-cache',
            // The script's own directory is /dashboard/, a scope that would
            // leave out /dashboard itself, the page the app opens on.
            'Service-Worker-Allowed' => '/'.$panel->path(),
        ]);
    }
}
