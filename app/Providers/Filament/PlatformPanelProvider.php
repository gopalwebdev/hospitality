<?php

namespace App\Providers\Filament;

use App\Enums\FilamentPanel;
use App\Filament\Platform\Auth\Login;
use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The product team's panel, served at hospitality.com/dashboard and entered
 * through hospitality.com/login.
 *
 * It is bound to the bare host so it can never be reached from a tenant
 * subdomain, and it is the default panel because it carries no tenancy. It
 * shares /dashboard with every tenant's panel and is told apart by host,
 * which holds because this provider is registered first (bootstrap/providers.php).
 */
class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id(FilamentPanel::Platform->value)
            ->path(FilamentPanel::Platform->path())
            ->domain(config('app.domain'))
            ->login(Login::class)
            ->brandName(FilamentPanel::Platform->brandName())
            ->brandLogo(fn (): View => view('filament.brand', ['panel' => FilamentPanel::Platform]))
            ->brandLogoHeight('2rem')
            ->colors([
                'primary' => Color::Indigo,
            ])
            // The sidebar folds down to its icons, handing its width to the page.
            ->sidebarCollapsibleOnDesktop()
            // The page takes all of that width. Filament centres it at 80rem
            // otherwise, between two empty bands. See .ai/rules/providers-filament.md.
            ->maxContentWidth(Width::Full)
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\Filament\Platform\Resources')
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\Filament\Platform\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Platform/Widgets'), for: 'App\Filament\Platform\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            // Panels are for laptops and larger; a phone is shown a door
            // rather than a layout nobody designed. See .ai/rules/filament.md.
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): View => view('filament.desktop-only'),
            )
            // Installable, so the panel opens in a window of its own. See
            // PanelProgressiveWebAppController.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): View => view('filament.progressive-web-app', [
                    'panel' => FilamentPanel::Platform,
                    'manifestUrl' => route('platform.manifest'),
                    'serviceWorkerUrl' => route('platform.service-worker', absolute: false),
                ]),
            )
            // The language this panel is worked in. Menu names come out of
            // translated columns, so this has to be a server round trip.
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): View => view('filament.language-switcher'),
            )
            // Page to page inside a panel is a Livewire visit rather than a
            // browser load, which puts a progress bar across the top while the
            // next page is fetched. Hovering a link fetches its page ahead of
            // the click, so most navigation is done by the time it lands. The
            // guest app gets the same from Inertia's prefetching.
            ->spa(hasPrefetching: true)
            // A resource with no policy, or a policy missing the method being
            // asked about, is refused rather than waved through. Without this a
            // page added tomorrow is open to anyone who can reach the panel,
            // and nothing says so.
            ->strictAuthorization()
            // A create or edit page runs with no transaction otherwise, so a
            // validation exception thrown mid-save (EnsureRoleFitsWithinLimit,
            // for one) leaves whatever already ran committed — a user row with
            // no role, or a rename that "failed". This is what makes a refusal
            // actually refuse nothing rather than half of it.
            ->databaseTransactions()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // Panels do not run the `web` group, so the middleware that
                // reads the language cookie is listed here too.
                SetLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
