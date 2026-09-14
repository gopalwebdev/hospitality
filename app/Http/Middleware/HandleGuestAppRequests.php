<?php

namespace App\Http\Middleware;

use App\Enums\Appearance;
use App\Enums\Currency;
use App\Enums\Locale;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * The guest app, served at the root of a tenant's subdomain.
 *
 * Its own root template, so a guest downloads the guest entry and one page
 * chunk and never a byte of Filament, which the panels serve from their own
 * compiled assets. Resolved from the subdomain, and read in the visitor's own
 * language and shade of light or dark.
 */
class HandleGuestAppRequests extends Middleware
{
    /**
     * @var string
     */
    #[\Override]
    protected $rootView = 'guest';

    /**
     * Put the theme and the tenant in front of the root template.
     *
     * Shared with the view rather than sent as an Inertia prop, because both
     * have to be in the HTML of the very first response: React runs after the
     * paint, and the manifest and the service worker are linked from the head.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenant($request);

        View::share('theme', $this->themeFor($tenant, $request));

        // Every tenant route carries {tenant} in its domain, so the root
        // template's manifest and service worker URLs need the slug.
        View::share('tenantSlug', $tenant?->slug);

        return parent::handle($request, $next);
    }

    /**
     * The props shared with every page of the guest app.
     *
     * The tenant, its currency and the app's own strings are once props:
     * none of them changes while a guest walks between the screens of one
     * tenant, so each is sent on the first visit, remembered by the client,
     * and left out of every visit after it — which is most of a page's payload
     * on a phone. The language and the shade are not, because the guest can
     * change both.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $tenant = $this->tenant($request);
        $locale = Locale::fromRequestValue(app()->getLocale());

        return [
            ...parent::share($request),
            'tenant' => Inertia::once(fn (): ?array => $tenant instanceof Tenant ? [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null),
            // A code and a scale rather than a formatted string, because prices
            // are formatted in the browser — see resources/js/lib/money.ts.
            'currency' => Inertia::once(function () use ($tenant): ?array {
                $currency = $tenant?->currency();

                return $currency instanceof Currency ? [
                    'code' => $currency->value,
                    'minorUnitDigits' => $currency->minorUnitDigits(),
                ] : null;
            }),
            'translations' => Inertia::once(fn (): array => $this->translations()),
            'locale' => [
                'current' => $locale->value,
                // The one the toggle switches to. Worked out here rather than
                // in React so the button can name its destination without the
                // front end having to know the list of languages.
                'next' => $locale->next()->value,
                'available' => array_map(
                    static fn (Locale $available): array => [
                        'value' => $available->value,
                        'label' => $available->label(),
                        'shortLabel' => $available->shortLabel(),
                    ],
                    Locale::cases(),
                ),
            ],
            // What the page was painted with, so the toggle starts in the right
            // state instead of guessing and correcting itself.
            'appearance' => $this->appearanceFor($request)->value,
        ];
    }

    /**
     * The guest app's chrome, which is written in English and stays that way.
     *
     * `lang/en` is the only language directory. What a tenant *wrote* is
     * translated in the database (`.ai/rules/models.md`), so switching language
     * changes the menu a guest reads without changing the words around it.
     *
     * @return array<string, mixed>
     */
    private function translations(): array
    {
        /** @var array<string, mixed> $chrome */
        $chrome = Lang::get('guest', [], Locale::default()->value);

        return $chrome;
    }

    /**
     * The theme handed to the root template: a name, and light or dark.
     *
     * @return array<string, string>
     */
    private function themeFor(?Tenant $tenant, Request $request): array
    {
        return [
            'name' => $tenant->name ?? config('app.name'),
            'appearance' => $this->appearanceFor($request)->value,
        ];
    }

    /**
     * Light or dark, as the phone reading this has been told.
     *
     * Read from the cookie rather than a prop because the answer has to be in
     * the first byte of HTML. The cookie is visitor-controlled and can come
     * back as an array, so it is checked rather than cast.
     */
    private function appearanceFor(Request $request): Appearance
    {
        $cookie = $request->cookie('appearance');

        return Appearance::fromRequestValue(is_string($cookie) ? $cookie : null);
    }

    /**
     * The tenant this request is being served for.
     */
    private function tenant(Request $request): ?Tenant
    {
        $tenant = $request->route('tenant');

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
