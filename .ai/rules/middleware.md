---
paths:
  - 'app/Http/Middleware/**'
  - app/Http/Middleware/SetLocale.php
---

# Middleware

## HandleGuestAppRequests is the one Inertia middleware on a subdomain
It picks the `guest` root template, shares `$theme` and `$tenantSlug` with it, and sends the shared props. `tenant`, `currency` and `translations` are Inertia **once props**: none changes while a guest walks between one tenant's screens, so each is sent on the first visit and left out of every visit after it, which is most of a page's payload on a phone. `locale` and `appearance` are sent every time, because the guest can change both. A tenant-wide abstract base and a staff subclass existed; both went with the staff app, as did `EnsureStaffMemberWorksHere`.

There is no `redirectGuestsTo` in `bootstrap/app.php` any more: nothing outside the panels uses `auth`, and each panel carries its own sign-in page.

## Security headers are global, because a panel does not run the web group
`AddSecurityHeaders` is registered with `$middleware->append()` in `bootstrap/app.php`, not on the `web` group. Filament builds its own middleware stack, so a header added to `web` alone would cover the guest app and **neither panel** — the same reason `SetLocale` is listed on each panel separately.

What it sets, and the two that are decisions rather than defaults:

- **`X-Frame-Options: SAMEORIGIN`**, not `DENY`. The guest app embeds a tenant's uploaded PDF in a same-origin iframe (`pages/guest/document.tsx`); `DENY` blanks it.
- **`Strict-Transport-Security` only when `$request->secure()`.** Over plain HTTP it means nothing, and sending it locally pins the browser to https for the whole `.test` domain long after the work is done — which is painful to undo by hand.
- `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, and a `Permissions-Policy` switching off camera, microphone, geolocation and payment, none of which either surface asks for.

**There is deliberately no Content-Security-Policy.** Filament and Livewire both inline scripts and styles, and so does Vite in development, so a real policy needs a per-request nonce threaded through every render hook and Blade layout. A policy loose enough to avoid that (`unsafe-inline`, `unsafe-eval`) is a header that looks reassuring and stops nothing. Add one properly as its own piece of work, or not at all — do not add a permissive one to tick the box.

`tests/Feature/SecurityHeadersTest.php` pins all of it, including the panel, which is the surface a `web`-group registration would have missed.

## SetLocale runs first in the web group, and its cookie is untrusted
SetLocale is appended to the `web` group in bootstrap/app.php **before** HandleInertiaRequests, so everything downstream — the Inertia props, the translated columns a controller reads, the root template's `lang` attribute — renders in the language it chose. Moving it after the Inertia middleware silently serves English props on a Tamil page.

Its cookie is listed in `encryptCookies(except: [...])` alongside `appearance`, because the toggle in React has to read the current value before the server can tell it. That makes both cookies visitor-controlled, so neither is ever trusted: a value that is not a known App\Enums\Locale case falls back to `config('app.locale')` and then to English, and `$request->cookie()` can return an array, so it is type-checked rather than cast.
