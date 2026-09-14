<?php

use App\Enums\FilamentPanel;
use App\Http\Controllers\PanelProgressiveWebAppController;
use App\Http\Controllers\PanelSignInController;
use App\Http\Controllers\Preferences\UpdateLanguageController;
use Illuminate\Support\Facades\Route;

// Subdomain routes first: an unconstrained route would otherwise swallow them.
require __DIR__.'/tenant.php';

Route::inertia('/', 'welcome')->name('home');

/*
 * The way into the product team's panel. The panel itself lives under
 * /dashboard; this sends someone to its sign-in page, or to the dashboard when
 * they are already signed in. See PanelSignInController.
 */
Route::get('login', [PanelSignInController::class, 'platform'])->name('platform.login');

/*
 * What makes the product team's panel installable, under the panel's own path so
 * the worker's scope is the panel. See PanelProgressiveWebAppController.
 */
Route::name('platform.')->prefix(FilamentPanel::Platform->path())->group(function (): void {
    Route::get('manifest.webmanifest', [PanelProgressiveWebAppController::class, 'platformManifest'])->name('manifest');
    Route::get('service-worker.js', [PanelProgressiveWebAppController::class, 'platformServiceWorker'])->name('service-worker');
});

/*
 * Switching language from the product team's panel, which is the one surface
 * not served from a tenant's subdomain. Same controller as the tenant
 * route in tenant.php — it needs its own registration only because a form must
 * post to the host it was rendered on, or the session cookie does not travel.
 */
Route::put('preferences/language', UpdateLanguageController::class)
    ->name('panel.language.update');
