---
paths:
  - 'resources/js/**'
---

# Js

## One app on a subdomain: one entry, one page directory
`resources/js/guest.tsx` is the Inertia entry for the guest app, and `pages: './pages/guest'` scopes it to that directory — which is what keeps its bundle to itself. `resources/js/app.tsx` is the root domain's welcome page and nothing else. A staff entry and `pages/staff` existed and were removed with the staff app; if one comes back it is a second entry with its own directory, never pages mixed into this one.

- Component names are relative to the app's own directory: render `'menu'`, not `'guest/menu'`. `config/inertia.php` lists `js/pages` and `js/pages/guest` under `pages.paths` so `assertInertia()` can find them.
- The guest app's root template is `resources/views/guest.blade.php`, chosen by `HandleGuestAppRequests`. Adding a page means putting it under `pages/guest`, nothing more.

## Vitest specs live in resources/js/tests, never beside a page
`vp test` (Vitest, configured in the `test` block of vite.config.ts) only collects `resources/js/tests/**/*.test.{ts,tsx}`.

Do not co-locate a spec inside `resources/js/pages/` — Inertia resolves every file under that directory as a page component, so `welcome.test.tsx` would register as a page named "welcome.test" and get bundled into the production build.

`resources/js/tests/setup.ts` stubs Inertia's `<Head>`, which otherwise throws because its head manager only exists after createInertiaApp has run.

## React is the phone lane
The guest app is designed at phone width and judged there. Lay out for one narrow column, size tap targets for thumbs, and never rely on hover to make something usable. Desktop breakpoints are not the job: reach for sm:/md:/lg: only to stop a page looking stretched on a wider screen, never to build a second layout, and never let a phone carry the cost of one.

## The guest app is installable, and does not work offline
A guest who comes back keeps the tenant on their home screen, so the app is a PWA: `guest.blade.php` links `guest.manifest` and registers `guest.service-worker`, both served per tenant by `Guest\ProgressiveWebAppController` from the root of the subdomain — so the worker's scope is the whole app, and a phone with two tenants installed shows two apps. The icons are `public/icon-192.png`, `icon-512.png` and `icon-maskable-512.png`. This reverses an earlier decision that guests "arrive by QR and leave" and get no install prompt; the project owner asked for it.

The worker is deliberately thin. Hashed `/build/` assets are answered from the cache once fetched; a page navigation goes to the network and falls back to the last good copy; **Inertia's own requests are never cached**, because the same URL answers HTML to a navigation and JSON to Inertia and handing one to the other breaks both. There is still no offline requirement: the PWA buys a home-screen icon and standalone chrome, not an offline menu.

## The app carries a theme toggle and a language toggle, and no logo
`components/preference-toggles.tsx` pairs the two things a visitor can change for themselves, and rides in `components/app-bar.tsx` on every screen.

The theme is one icon button, light ⇄ dark, and the icon shows the destination rather than the current state. There is no third "system" state and no brand colour — light and dark are the whole of the theming. It is client-side only: `useAppearance().toggleAppearance()` writes localStorage and the `appearance` cookie, and the cookie is what lets the server paint the next first response the same way (see `.ai/rules/views.md`).

The language is a real form `PUT`ing to `preferences.language.update`, not a client-side switch, because half of what a guest reads — item names, sections, tile labels — is translated in the database and only the server can answer in another language. The server decides which language is next, so the button never holds the list.

There is deliberately **no logo** in the app's chrome. The tenant's name is text and its brand colour is already on every button and price; a logo slot would be an empty box for every tenant that has not uploaded one. The PWA manifest uses the generic app icons.

## Chrome strings come from lang/en, item names come from the page's props
`lang/en/guest.php` holds the app's chrome and is shared as the `translations` prop — an Inertia once prop, sent on the first visit and remembered by the client; read them with `useTranslations()` and a dotted path, `t('menu.empty')`. Laravel's `:name` placeholders are filled in the browser, so `t('login.code_intro', { length: 6 })` — not a second string with the number baked in.

There is **one** language directory, deliberately: this application's words are English, and what gets translated is what a tenant wrote (`.ai/rules/lang.md`). A guest who switches to Tamil gets their menu in Tamil and this chrome unchanged. A missing key falls back to the path itself, so a typo reads as `menu.empy` rather than as nothing.

Everything a tenant wrote — menu, category, item, add-on, charge and tile names — arrives on the page's own props, already in the right language. Never translate those in React.

A chrome string that names the business says "tenant", whatever the tenant's type. The guest app is sent no type at all.

## Nothing waits on a blank screen
Two layers, and both are needed because they cover different gaps:

- **The first load** is covered by `resources/views/partials/boot-loader.blade.php`, included in the guest root template after `<x-inertia::app />`. It is CSS only — `#app:not(:empty) ~ #boot-loader { display: none }` — so it is correct on the first paint, before any JavaScript has run, and it disappears the moment Inertia renders into `#app`, with nothing to unmount and no timer to get wrong. The combinator is `~` and not `+` because the partial's own `<style>` block is a sibling sitting between the two: with `+` the rule matched nothing and the spinner sat over a fully loaded page for ever. A test asserts the selector and that the app div comes first, because nothing else here can catch a rule that simply never matches.
- **Every navigation after it** is Inertia's own progress bar, configured in the guest entry with a 100ms delay rather than its default 250 — a guest tapping on a phone should see that the tap registered.
- **Most navigations are already fetched.** A tile and the back arrow carry `prefetch={['hover', 'click']}`, so the next page is requested the moment a finger lands on the link (or on hover, where there is a pointer) and is usually loaded by the time the tap completes.

The panels have the same from their own stack: `->spa(hasPrefetching: true)` makes a click a Livewire visit with a progress bar, prefetched on hover (`.ai/rules/filament.md`).

## The guest app has three screens
`pages/guest` holds `home` (the tiles a guest lands on), `menu` (one menu: its featured rail, its combos, its sections with their subdivisions, the items in each and their add-ons, and the small print about tax and charges) and `document` (a tile's PDF, embedded so the app keeps its back arrow).

Things about the menu screen worth knowing before editing it. It renders **the order it is sent**: `order` is a list of `'featured' | 'combos' | <section id>` built by `Menu::readingOrder()` on the server from the menu's categories and its `menu_blocks`, and the page maps over it picking `FeaturedRail`, `CombosRail` or `SectionBlock` — because where a menu leads with its combos is a tenant's decision, and decisions stay in PHP (`.ai/rules/general.md`). Headings are nested for real — category `h2`, sub-category `h3`, item `h3` when filed straight under a category and `h4` inside a subdivision — which is why `Item` takes a `headingLevel`; someone navigating by headings is reading the menu's actual structure. And a rate arrives as **basis points** (500 is 5%), not a percentage, because that is how it is stored so the arithmetic behind a bill stays in integers — the `percentage()` helper turns it into something to read, beside the money formatting and for the same reason. The rule above still holds: component names are relative to the app's own directory.

An item is something to order or a service request: it arrives with `isServiceRequest`, and `diet` is null for a service request. `components/diet-mark.tsx` draws the regulatory veg / egg / non-veg mark, labelled for screen readers, and keeps the mark's empty space (hidden from them) for a service request so every name starts at the same edge. A price of 0 reads "Complimentary"; an add-on at 0 reads "Free". The server sends the zero and never a word.

The small print is `tax` (`rateBasisPoints`, `pricesIncludeTax`) and then `charges`: only the switched-on charges this menu carries, in the tenant's order, each with exactly one of `rateBasisPoints` or `amountMinorUnits` set. Which charges apply is decided in PHP; the page only words them, one line each.

Vitest specs render a page directly, outside `createInertiaApp`, so `usePage()` has nowhere to read from. `resources/js/tests/setup.ts` mocks it against `resources/js/tests/page-props.ts`; call `stubPageProps()` to change what a test sees. Its strings are a stand-in, not the real ones — what each app actually says is pinned by `tests/Feature/LocalizationTest.php`.

## Money is formatted here, never in PHP
Prices cross the wire as an integer count of the currency's minor unit — ₹249.50 is `priceMinorUnits: 24950` — exactly as the database stores them, and the tenant's currency arrives once in the shared `currency` prop as a code and a scale. `useMoney()` turns the two into a string.

Two reasons, both load-bearing. The server does no per-row string building, which is the point of `.ai/rules/general.md`'s memory rule. And `Intl.NumberFormat` follows the guest's own language, so a rupee price groups as ₹2,49,500 rather than ₹249,500 — something `number_format()` cannot do.

Formatters are cached per locale-and-currency in `resources/js/lib/money.ts`, because a menu formats one per row. The scale comes from the server so a zero-decimal currency is never silently divided by 100. The one place money is still formatted in PHP is a Filament table, which is server rendered — it goes through `MenuItem::formattedPrice()`.

## Built assets are precompressed with Brotli and gzip, and pages stay lazy
`vite.config.ts` carries a `precompress()` plugin that writes a `.br` (Brotli at maximum quality) and a `.gz` beside every built text asset over a kilobyte, using Node's own zlib — no dependency. Compressing once at build time beats compressing per request, but only if the server hands the copy over: nginx needs `brotli_static on;` and `gzip_static on;` for `/build`, or the CDN in front of Laravel Cloud does it. Herd's local nginx does neither, so locally the copies sit unused and the uncompressed asset is served; that is expected, not a bug.

Page components stay lazy — the Inertia Vite plugin splits one chunk per page, and `Vite::prefetch()` warms the other guest pages after load — and images are `loading="lazy" decoding="async"`.

## A spinner that never goes away means the bundle threw
The boot loader hides only once React renders into `#app`, so any uncaught error on first render leaves the guest app spinning for ever rather than showing an error. Read `storage/logs/browser.log` first.

The usual cause is a stale `public/build` after a shared prop was renamed on the server: the September build still read `props.restaurant` after it became `tenant`, so `restaurant.slug` threw on every load. `npm run build` (or `npm run dev`) fixes it, and also regenerates the Wayfinder files, which bake `APP_DOMAIN` into every tenant route URL — rebuild after changing the domain too.

## A stale public/hot file points the whole site at a dead Vite server
Laravel's Vite integration decides whether to emit dev-server script tags (`https://hospitality.test:5173/...`) purely by checking whether `public/hot` exists — never by checking that anything is actually listening on that port. `npm run dev` / `composer dev` writes it on start and removes it on a clean exit, but a killed or crashed dev server (Ctrl+C sometimes doesn't clean it up, a terminal closed mid-session always doesn't) leaves it behind.

Symptom: pages load fine in a browser with the dev server running, but `curl`, `php artisan test`, or a browser after the dev server is gone all get dev-server URLs that resolve to nothing — `GuestAppTest`'s "loads only its own entry and page" fails with the built manifest hash missing from the HTML entirely. Fix is `rm public/hot`, not a rebuild. If `npm run build` was run afterward, the manifest is already correct — the hot file was the only thing lying.

## vite is an alias of vite-plus-core, and npm carries no engine pin
vite-plus 0.3.1 refuses to build unless `vite` resolves to its own core, so `package.json` declares `"vite": "npm:@voidzero-dev/vite-plus-core@<version>"` in `dependencies` **and** in `overrides`, as `vp migrate` wrote it. Without the override, `npm update` installs the real `vite` and `vp build` fails with "Expected @voidzero-dev/vite-plus-core". Bump both together with `vite-plus`.

`vp migrate` also writes a `devEngines.packageManager` pin to npm 12. It was taken out, because Node 24 — locally and in CI — ships npm 11, and the pin makes every npm command refuse to run. Take it out again if a later `vp migrate` puts it back.
