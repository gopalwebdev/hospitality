---
paths:
  - 'routes/**'
---

# Routes

## Routes follow OpenAPI resource naming
Paths name resources, never actions: plural lowercase kebab-case nouns, with the HTTP method carrying the verb. `GET /menu-items`, `POST /menu-items`, `PATCH /menu-items/{menuItem}` — never `/getMenuItems`, `/menu_items` or `/create-menu-item`. No trailing slash.

Nest only to express ownership, one level where possible: `/tenants/{tenant}/menu-items`. Path parameters are camelCase and match the route-model-binding variable.

API routes are versioned from the first one: `/api/v1/...`. Route names are dot-separated and mirror the path (`menu-items.index`), and links are always built with `route()` or the generated Wayfinder helper, never a hand-written string.

## Tenant route names say which app they belong to
Every route name on a tenant's subdomain carries its app's prefix — `guest.home`, `guest.menus.show`, `guest.menus.basket-quotes.store`, `guest.menus.orders.store`, `guest.tiles.document.show`, `guest.manifest`, `guest.service-worker` — so a second app there later cannot collide. The one exception is `preferences.language.update`, which the guest app and the tenant's panel both post to and neither owns.

`manifest.webmanifest` and `service-worker.js` are the two paths that are not resource nouns: they are the filenames browsers expect. The guest app's are served from the root of the subdomain so the worker's scope is the whole app.

The panels serve the same two files under their own path, so a panel's worker never replaces the guest app's (`.ai/rules/filament.md`):
- **Product team:** `platform.manifest` and `platform.service-worker`, in `routes/web.php`.
- **Tenant:** `tenant.manifest` and `tenant.service-worker`, in `routes/tenant.php`.

The prefix is `FilamentPanel::path()`, never a typed `dashboard`. Filament registers nothing at those two paths.

`POST /menus/{menu}/basket-quotes` is the guest app's one JSON endpoint: a basket kept on the phone, priced against the menu it came from (`Guest\BasketQuoteController`, `.ai/rules/actions-menus.md`). A quote is the resource created, so the path is a noun like every other. It sits in the guest middleware group beside the menu it belongs to, is throttled to 60 a minute because anyone at a table can reach it, and checks the tenant and the menu by hand like every guest controller.

Switching language needs two registrations of one controller, because a form must post to the host it was rendered on or the session cookie does not travel: `preferences.language.update` on a tenant's subdomain (the guest app and the tenant panel) and `panel.language.update` on the root domain (the product team's panel).

`storefront` was the old name for `guest.home`, and `/` is now the tile home screen rather than the menu — a menu lives at `/menus/{menu}`. Because `{tenant}` arrives in the **domain**, Laravel's scoped bindings do not cover `{menu}` or `{tile}`: each controller checks `$model->tenant_id === $tenant->getKey()` by hand, alongside the `is_active` check. Leaving that out is how one tenant reads another's uploads off the same disk.

## Locally, Herd is the server; nothing runs artisan serve
Herd serves `https://hospitality.test` and every `https://{slug}.hospitality.test` on 80 and 443, so the app's URLs carry no port. Tenant routes are bound to `{tenant}.` + `APP_DOMAIN`, which is why `127.0.0.1:8000` only ever reaches the root-domain routes — the guest app and the tenant panel are unreachable there.

`php artisan serve` can never print or answer a portless URL, because Herd's nginx owns those ports, so it is not part of the workflow: `AppServiceProvider::configureDevProcesses()` leaves the `server` process out of `php artisan dev` (and so `composer dev`). There is deliberately no `SERVER_HOST` in `.env`; setting it only changes `127.0.0.1:8000` into `hospitality.test:8000`.
