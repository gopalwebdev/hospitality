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
Every route name on a tenant's subdomain carries its app's prefix — `guest.home`, `guest.menus.show`, `guest.tiles.document.show`, `guest.manifest`, `guest.service-worker` — so a second app there later cannot collide. The one exception is `preferences.language.update`, which the guest app and the tenant's panel both post to and neither owns.

`manifest.webmanifest` and `service-worker.js` are the two paths that are not resource nouns: they are the filenames browsers expect, served from the root of the subdomain so the worker's scope is the whole app.

Switching language needs two registrations of one controller, because a form must post to the host it was rendered on or the session cookie does not travel: `preferences.language.update` on a tenant's subdomain (the guest app and the tenant panel) and `panel.language.update` on the root domain (the product team's panel).

`storefront` was the old name for `guest.home`, and `/` is now the tile home screen rather than the menu — a menu lives at `/menus/{menu}`. Because `{tenant}` arrives in the **domain**, Laravel's scoped bindings do not cover `{menu}` or `{tile}`: each controller checks `$model->tenant_id === $tenant->getKey()` by hand, alongside the `is_active` check. Leaving that out is how one tenant reads another's uploads off the same disk.

## Locally, Herd serves the tenant subdomains; artisan serve on 127.0.0.1 cannot
Tenant routes are bound to `{tenant}.` + `APP_DOMAIN`, so a request to `127.0.0.1:8000` only ever reaches the root-domain routes — the guest app and the tenant panel are unreachable there. Herd already serves `https://hospitality.test` and every `https://{slug}.hospitality.test`; `php artisan serve` is not needed.

`.env` sets `SERVER_HOST="${APP_DOMAIN}"`, which `ServeCommand` reads as its default host, so `php artisan serve` (and the `server` process in `php artisan dev`) prints `http://hospitality.test:8000` and answers `http://{slug}.hospitality.test:8000` too — `.test` resolves to 127.0.0.1 through Herd. The port stays because Herd's nginx owns 80 and 443.
