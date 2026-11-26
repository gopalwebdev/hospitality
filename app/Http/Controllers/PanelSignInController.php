<?php

namespace App\Http\Controllers;

use App\Enums\FilamentPanel;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * /login, on the root domain and on every tenant's subdomain.
 *
 * The one address to give anyone who uses a panel, whatever they do there.
 * Filament keeps a panel's sign-in page under the panel's own path
 * (/dashboard/login), so this is a door rather than a page: someone signed out
 * is sent to that sign-in page, and someone already signed in goes straight to
 * /dashboard.
 */
class PanelSignInController extends Controller
{
    /**
     * The product team's way in, on the root domain.
     */
    public function platform(): RedirectResponse
    {
        return $this->enter(FilamentPanel::Platform);
    }

    /**
     * A tenant's way in, for its owners and staff alike, on its subdomain.
     */
    public function tenant(Tenant $tenant): RedirectResponse
    {
        return $this->enter(FilamentPanel::Tenant, ['tenant' => $tenant]);
    }

    /**
     * Filament types getPanel() as nullable even though it throws on an
     * unknown id, so a panel this application registers itself is asserted
     * by failing loudly rather than by widening what enter() accepts.
     *
     * @param  array<string, mixed>  $dashboardParameters
     */
    private function enter(FilamentPanel $filamentPanel, array $dashboardParameters = []): RedirectResponse
    {
        $panel = Filament::getPanel($filamentPanel->value)
            ?? throw new RuntimeException("The {$filamentPanel->value} panel is not registered.");

        if ($panel->auth()->check()) {
            return redirect()->to(route($panel->generateRouteName('pages.dashboard'), $dashboardParameters));
        }

        return redirect()->to((string) $panel->getLoginUrl());
    }
}
