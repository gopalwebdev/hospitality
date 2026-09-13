---
paths:
  - 'resources/views/**'
---

# Views

## Light or dark is injected into the root template, never sent as a prop
`resources/views/partials/theme.blade.php` writes the visitor's light/dark choice into the HTML of the first response, and leaves it on the root element as `data-appearance` for React to read back.

It has to be there, not in an Inertia prop: React runs after the first paint, so a prop would show one shade and then visibly correct itself in front of the guest. `initializeTheme()` in resources/js/hooks/use-appearance.tsx reads that attribute rather than guessing, and deliberately persists nothing — only tapping the toggle is a choice worth storing.

There is **no brand colour and no per tenant theme**. `tenant_settings` used to carry `theme_primary_color` and `theme_appearance`; both were dropped, the tenant panel offers no theming, and `App\Enums\Appearance` has exactly two cases. Do not reintroduce either without asking.

`HandleGuestAppRequests` shares two view variables for this — `$theme` and `$tenantSlug`. The slug is required because every tenant route carries `{tenant}` in its **domain**, so `route('guest.manifest')` without it throws `UrlGenerationException`. Any new tenant URL built in a Blade template needs `['tenant' => $tenantSlug]`.
