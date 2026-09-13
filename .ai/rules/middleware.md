---
paths:
  - 'app/Http/Middleware/**'
  - app/Http/Middleware/SetLocale.php
---

# Middleware

## HandleGuestAppRequests is the one Inertia middleware on a subdomain
It picks the `guest` root template, shares `$theme` and `$tenantSlug` with it, and sends the shared props. `tenant`, `currency` and `translations` are Inertia **once props**: none changes while a guest walks between one tenant's screens, so each is sent on the first visit and left out of every visit after it, which is most of a page's payload on a phone. `locale` and `appearance` are sent every time, because the guest can change both. A tenant-wide abstract base and a staff subclass existed; both went with the staff app, as did `EnsureStaffMemberWorksHere`.

There is no `redirectGuestsTo` in `bootstrap/app.php` any more: nothing outside the panels uses `auth`, and each panel carries its own sign-in page.

## SetLocale runs first in the web group, and its cookie is untrusted
SetLocale is appended to the `web` group in bootstrap/app.php **before** HandleInertiaRequests, so everything downstream — the Inertia props, the translated columns a controller reads, the root template's `lang` attribute — renders in the language it chose. Moving it after the Inertia middleware silently serves English props on a Tamil page.

Its cookie is listed in `encryptCookies(except: [...])` alongside `appearance`, because the toggle in React has to read the current value before the server can tell it. That makes both cookies visitor-controlled, so neither is ever trusted: a value that is not a known App\Enums\Locale case falls back to `config('app.locale')` and then to English, and `$request->cookie()` can return an array, so it is type-checked rather than cast.
