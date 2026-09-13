<?php

namespace App\Providers\Filament;

use App\Enums\FilamentPanel;
use App\Filament\Restaurant\Auth\Login;
use App\Http\Middleware\SetLocale;
use App\Models\Restaurant;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
 * A restaurant's own panel, for its admins and staff alike, served at
 * t1.restaurant-app.com/dashboard and entered through t1.restaurant-app.com/login.
 *
 * The subdomain identifies the tenant, so every resource registered here is
 * automatically scoped to the restaurant in the URL.
 */
class RestaurantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id(FilamentPanel::Restaurant->value)
            ->path(FilamentPanel::Restaurant->path())
            ->tenant(Restaurant::class, slugAttribute: 'slug')
            ->tenantDomain('{tenant:slug}.'.config('app.domain'))
            ->login(Login::class)
            ->brandName(fn (): string => $this->brandName())
            ->brandLogo(fn (): View => view('filament.brand', [
                'panel' => FilamentPanel::Restaurant,
                'name' => $this->brandName(),
            ]))
            ->brandLogoHeight('2rem')
            ->colors([
                'primary' => Color::Amber,
            ])
            // Signed in, a restaurant sees only its own name in the topbar,
            // not a name plus a switcher into other restaurants: an admin
            // panel is scoped to one restaurant, and there is nowhere else to
            // switch to. A super admin supporting one restaurant opens it from
            // the Restaurants table in their own panel instead. See
            // .ai/rules/filament.md.
            ->tenantMenu(false)
            ->discoverResources(in: app_path('Filament/Restaurant/Resources'), for: 'App\Filament\Restaurant\Resources')
            ->discoverPages(in: app_path('Filament/Restaurant/Pages'), for: 'App\Filament\Restaurant\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Restaurant/Widgets'), for: 'App\Filament\Restaurant\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            // Panels are for laptops and larger; a phone is shown a door
            // rather than a layout nobody designed. See .ai/rules/filament.md.
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): View => view('filament.desktop-only'),
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

    /**
     * What this panel calls itself: the restaurant's own name once someone is
     * signed in and a tenant is known, and the generic panel name on the
     * sign-in page, where there is no tenant yet to name.
     */
    private function brandName(): string
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Restaurant ? $tenant->name : FilamentPanel::Restaurant->brandName();
    }
}
