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

The panels on the same subdomain are installable too, with a worker under `/dashboard` that does nothing, so they never replace or share this one (`.ai/rules/filament.md`).

## The app carries a theme toggle and a language toggle, and no logo
`components/preference-toggles.tsx` pairs the two things a visitor can change for themselves, and rides in `components/app-bar.tsx` on every screen.

The theme is one icon button, light ⇄ dark, and the icon shows the destination rather than the current state. There is no third "system" state and no brand colour — light and dark are the whole of the theming. It is client-side only: `useAppearance().toggleAppearance()` writes localStorage and the `appearance` cookie, and the cookie is what lets the server paint the next first response the same way (see `.ai/rules/views.md`).

The language is a real form `PUT`ing to `preferences.language.update`, not a client-side switch, because half of what a guest reads — item names, sections, tile labels — is translated in the database and only the server can answer in another language. The server decides which language is next, so the button never holds the list.

There is deliberately **no logo** in the app's chrome. The tenant's name is text and its brand colour is already on every button and price; a logo slot would be an empty box for every tenant that has not uploaded one. The PWA manifest uses the generic app icons.

## Chrome strings come from lang/en, item names come from the page's props
`lang/en/guest.php` holds the app's chrome and is shared as the `translations` prop — an Inertia once prop, sent on the first visit and remembered by the client; read them with `useTranslations()` and a dotted path, `t('menu.empty')`. Laravel's `:name` placeholders are filled in the browser, so `t('login.code_intro', { length: 6 })` — not a second string with the number baked in.

There is **one** language directory, deliberately: this application's words are English, and what gets translated is what a tenant wrote (`.ai/rules/lang.md`). A guest who switches to Tamil gets their menu in Tamil and this chrome unchanged. A missing key falls back to the path itself, so a typo reads as `menu.empy` rather than as nothing.

Everything a tenant wrote — menu, category, item, add-on group, option, charge and tile names — arrives on the page's own props, already in the right language. Never translate those in React.

A chrome string that names the business says "tenant", whatever the tenant's type. The guest app is sent no type at all.

## Nothing waits on a blank screen
Two layers, and both are needed because they cover different gaps:

- **The first load** is covered by `resources/views/partials/boot-loader.blade.php`, included in the guest root template after `<x-inertia::app />`. It is CSS only — `#app:not(:empty) ~ #boot-loader { display: none }` — so it is correct on the first paint, before any JavaScript has run, and it disappears the moment Inertia renders into `#app`, with nothing to unmount and no timer to get wrong. The combinator is `~` and not `+` because the partial's own `<style>` block is a sibling sitting between the two: with `+` the rule matched nothing and the spinner sat over a fully loaded page for ever. A test asserts the selector and that the app div comes first, because nothing else here can catch a rule that simply never matches.
- **Every navigation after it** is Inertia's own progress bar, configured in the guest entry with a 100ms delay rather than its default 250 — a guest tapping on a phone should see that the tap registered.
- **Most navigations are already fetched.** A tile and the back arrow carry `prefetch={['hover', 'click']}`, so the next page is requested the moment a finger lands on the link (or on hover, where there is a pointer) and is usually loaded by the time the tap completes.

The panels have the same from their own stack: `->spa(hasPrefetching: true)` makes a click a Livewire visit with a progress bar, prefetched on hover (`.ai/rules/filament.md`).

## The guest app has three screens
`pages/guest` holds `home` (the tiles a guest lands on), `menu` (one menu: its featured rail, its combos, its sections with their subdivisions, the items in each, the sheet an item is customised in, the basket, and the small print about tax and charges) and `document` (a tile's PDF, embedded so the app keeps its back arrow).

Things about the menu screen worth knowing before editing it. It renders **the order it is sent**: `order` is a list of `'featured' | 'combos' | <section id>` built by `Menu::readingOrder()` on the server from the menu's categories and its `menu_rails`, and the page maps over it picking `FeaturedRail`, `CombosRail` or `SectionBlock` — because where a menu leads with its combos is a tenant's decision, and decisions stay in PHP (`.ai/rules/general.md`). Headings are nested for real — category `h2`, sub-category `h3`, item `h3` when filed straight under a category and `h4` inside a subdivision — which is why `Item` takes a `headingLevel`; someone navigating by headings is reading the menu's actual structure. And a rate arrives as **basis points** (500 is 5%), not a percentage, because that is how it is stored so the arithmetic behind a bill stays in integers — `lib/rate.ts`'s `formatRate()` turns it into something to read, beside the money and time formatting and for the same reason (it lived in `menu.tsx` until the basket needed it too). The rule above still holds: component names are relative to the app's own directory.

An item is something to order or a service request: it arrives with `isServiceRequest`, and `diet` is null for a service request. `components/item-mark.tsx` draws the mark beside every name, labelled for screen readers:
- **Something to order:** the regulatory veg / vegan / egg / non-veg square. Vegan is a teal square carrying a `LeafIcon` instead of the dot — a colour *and* a glyph, because a green square with a dot and a green square with a leaf are indistinguishable at 16px.

An item carries a **list** of marks in the database (`menu_items.diets` — most vegetarian items are vegan too), but `diet` arrives here as **one** value: `MenuItem::dietMark()` picks the strictest, which already says everything the others do, so the menu keeps one square per row. Do not add the list to the payload to draw several — that decision is `.ai/rules/enums.md`, and the panel is where every mark is shown.
- **A service request:** a sky-blue bell in the same square, a colour no diet uses. The project owner asked for it. Before it, a service request kept an empty space, and a card of room requests was a column of gaps.

An item's row reads name, price and description down the left and keeps the right for Add, with "Customisable" under it. A price of 0 reads "Complimentary" in quiet text, because on a card of room requests nearly every row is one; an option at 0 shows no price at all, because "Free" beside Mild, Medium and Hot is noise. The server sends the zero and never a word.

The small print is `tax` (`rate`, `pricesIncludeTax`) and then `charges`: only the switched-on charges this menu carries, in the tenant's order, each with exactly one of `rate` or `amount` set. Which charges apply is decided in PHP; the page only words them, one line each.

The tenant's own hours arrive as `store` — `{isOpen, opensAt, closesAt}`, the times as the stored `HH:MM` and both null on a day it does not open. `useClockTime()` is what turns one into a reading — "9:00 AM", never "09:00" — in the guest's own language, beside the money formatting and for the same reasons; a menu's `servedFrom` / `servedUntil` go through it too. `isOpen` is worked out on the server against the tenant's week (`.ai/rules/app.md`), never in the browser from the two times, because the phone's clock is not the tenant's. The page drives the Open/Closed badge and a line above the menu with it, so a guest reading a card after closing is told why the Add buttons are gone.

Vitest specs render a page directly, outside `createInertiaApp`, so `usePage()` has nowhere to read from. `resources/js/tests/setup.ts` mocks it against `resources/js/tests/page-props.ts`; call `stubPageProps()` to change what a test sees. Its strings are a stand-in, not the real ones — what each app actually says is pinned by `tests/Feature/LocalizationTest.php`.

## An item is customised in a sheet, and the basket lives on the phone
The menu is sent `addOnGroups` once — `{id, name, isRequired, maxPicks, options: [{id, name, price, maxPerItem, isDefault}]}`, only available options and only the groups an item on the page offers, each option's `maxPerItem` already capped at the *group's own* maximum. Each item names its groups, in its own order, as `addOnGroupLinks: {id, maxPicks}[]` — `maxPicks` is that item's own cap on the group's picks, null following the group's own default. `withItemMaxPicks()` (`lib/add-on-rules.ts`) is what merges an item's own cap onto a shared group for the sheet, clamping every option's `maxPerItem` down with it; a group offered on twenty items is still one entry on the wire; only the small `{id, maxPicks}` link is per item.

Add buttons appear only while `store.isOpen` and the menu `isBeingServed`. An item with no groups goes straight in. One with groups says "Customisable" and opens `components/customise-sheet.tsx`, a shadcn `sheet` from the bottom:
- a required pick-one is radios; anything else is checkboxes, with a stepper on an option allowed more than one
- an optional pick-one moves its tick rather than locking
- a full group stops offering the options not picked
- default options (`isDefault`) start ticked
- Add reads "Choose 1 more from Bread" until every required group has a pick

`lib/add-on-rules.ts` holds those rules as pure functions and counts picks as the server does, each option by its quantity. It only shapes the sheet: nothing it decides is trusted.

`hooks/use-basket.ts` keeps the lines in localStorage under `basket:{tenant slug}:{menu id}`, read through `useSyncExternalStore` with an empty server snapshot, because SSR is on and the first render has to match a page painted without the phone's storage. The same item with the same choices is one line. A line keeps ids and the name it was added under; the basket sheet names its lines from the page's props, so a language switch renames them.

**Items and combos carry a maximum per order**, sent as `maxPerOrder` (null is no limit) and counted across every basket line the item or combo is on. The project owner asked for it after a guest could ask for 27 pillows; a minimum per order was built beside it and taken out again. `lib/order-limits.ts` holds the arithmetic:
- **Customise sheet:** starts at one, and its stepper stops at what the maximum leaves.
- **Basket line:** + stops at the maximum.
- **A full basket:** Add stays on screen, greyed, with "Limit reached" under it. Hiding it would read as sold out.

The limit is worded under the sheet's stepper and each basket line ("Up to 2 per order"). On a line the server refused for it, the limit replaces "Choices need changing". The server is what refuses (`.ai/rules/actions-menus.md`).

Every number in `components/basket-sheet.tsx` and `components/basket-bar.tsx` is the server's. `hooks/use-basket-price.ts` posts the lines to `priceUrl` with Inertia's `useHttp` (`App\Actions\Baskets\PriceBasket`, see `.ai/rules/actions-menus.md`).

**The menu page owns the asking, not the sheet.** `pages/guest/menu.tsx` calls the hook with `basket.count > 0` and passes `priced`/`isPricing` down to both the sheet and the bar, so one answer feeds both. It used to be called inside the sheet with `open`, which meant nothing was priced until a guest looked — the bar at the foot of the menu shows the total now, so a basket with anything in it is priced whether or not the sheet has been opened. An empty basket is still never priced.

Three traps in that hook:
- `useHttp` hands back new helpers on every render, so the hook reads them through a ref; listing them as effect dependencies re-posts on every render.
- It calls `transform()` before `post()`, because `post()` sends from a ref that a `setData()` in the same tick has not updated yet.
- It waits `SETTLE_MS` before posting and clears the timer on cleanup, because a guest settling on a quantity taps a stepper several times a second and the endpoint is rate limited (60/min, shared by everyone at the table). This is only safe because `useBasket()` hands back a stable `lines` reference from `useSyncExternalStore` — if `lines` were rebuilt each render the effect would reschedule forever and never post at all.

**GST is shown twice over, which is what a bill here does.** Each line carries its own rate and amount ("GST 5% · ₹19.45"), because one basket can hold a 5% item beside an 18% one and a single figure at the foot would hide that. The foot carries the **parts** — CGST and SGST, or UTGST in a union territory, or one IGST — from `taxParts`, which is the server's split and is never re-derived in the browser (halving a total does not reliably add back up; see `.ai/rules/actions-menus.md`). A part that carries nothing is not drawn, so a tenant charging no GST shows no rows rather than three zeroes, and a complimentary line shows no GST line at all.

Where the prices already include GST, the parts move **below** the total as a note rather than sitting in the running list, because they are not being added — and the per-line label reads "Incl.". `lib/rate.ts` turns the basis points the server sends into "5%", beside the money and time formatting and for the same reason.

Nothing is ordered from here yet: the sheet tells the guest to show it to a member of staff. The server side of ordering exists — `POST menus/{menu}/orders` (`guest.menus.orders.store`) places the same lines and takes them from stock, answering 422 with `shortages` when something ran out — and the priced basket already carries `shortages`, which the sheet does not read. Wiring either in is the app's next step (`.ai/rules/inventory.md`). `guest-basket.test.tsx` mocks `use-basket-price`, so the basket is tested against a fixed answer.

`components/ui/sheet.tsx`, `radio-group.tsx` and `checkbox.tsx` were written from the shadcn registry with three changes: `cn` comes from `@/lib/utils`, the icons come from `components/icons.tsx`, and `SheetOverlay` is `bg-black/70 backdrop-blur-md` rather than the registry's flat `bg-black/50` — the project owner asked for the menu to fall further back when a sheet is over it. The registry imports `lucide-react`, which this app deliberately does not depend on, so do not let the CLI add it.

## A figure that changes counts to its new value
`components/money.tsx` is what draws money that can change under the guest — every basket line, every row of the totals, and the bar at the foot of the menu. `hooks/use-count-up.ts` tweens from the old figure to the new one over `DURATION_MS`, easing out, on `requestAnimationFrame`.

The reason is a complaint worth keeping: the totals used to be wrapped in `opacity-60` while the next answer was fetched, and swapping one number for another in a single frame read as a flicker — the eye caught that something changed but not what. **Nothing dims and nothing is blanked now.** Only the digits move; the label beside them never re-renders and nothing unmounts, so no text flickers. `aria-busy` still says a new answer is coming, because that is the part assistive tech needs.

Three cases snap instead of travelling, and all three matter: the **first render** (a figure counting up from zero would be wrong for the whole of that first journey, and every test reads the number straight after rendering), a guest who has asked for **reduced motion**, and anywhere `requestAnimationFrame` is missing. `Money` carries `tabular-nums` itself — without it each frame is a different width and the row jitters.

A price that cannot change, such as one on the menu itself, does not need this and goes through `useMoney()` directly.

The basket's **"Clear basket"** button is worded as the action it is. It read "Empty basket" and, sitting under a total, was taken for a statement that the basket *was* empty.

## Money and times are formatted here, never in PHP
Prices cross the wire as an integer count of the currency's minor unit — ₹249.50 is `price: 24950` — exactly as the database stores them, and the tenant's currency arrives once in the shared `currency` prop as a code and a scale. `useMoney()` turns the two into a string.

Two reasons, both load-bearing. The server does no per-row string building, which is the point of `.ai/rules/general.md`'s memory rule. And `Intl.NumberFormat` follows the guest's own language, so a rupee price groups as ₹2,49,500 rather than ₹249,500 — something `number_format()` cannot do.

Formatters are cached per locale-and-currency in `resources/js/lib/money.ts`, because a menu formats one per row. The scale comes from the server so a zero-decimal currency is never silently divided by 100.

**A time of day is the same story.** Opening hours and a menu's service window cross the wire as the stored `HH:MM`, and `resources/js/lib/time.ts` — cached per locale, like the money formatters — reads them on a 12-hour clock (`.ai/rules/general.md`). Never send a built time string. **A rate likewise**: basis points on the wire, `lib/rate.ts` turns them into "5%".

The one place money and dates are still formatted in PHP is **a Filament panel, which is server rendered**: there is no client to format in, so a price goes through `MenuItem::formattedPrice()` and a timestamp through Filament's own `->dateTime()`, whose house format is set once in `AppServiceProvider`. That is the exception, and it is the only one.

## Built assets are precompressed with Brotli and gzip, and pages stay lazy
`vite.config.ts` carries a `precompress()` plugin that writes a `.br` (Brotli at maximum quality) and a `.gz` beside every built text asset over a kilobyte, using Node's own zlib — no dependency. Compressing once at build time beats compressing per request, but only if the server hands the copy over: nginx needs `brotli_static on;` and `gzip_static on;` for `/build`, or the CDN in front of Laravel Cloud does it. Herd's local nginx does neither, so locally the copies sit unused and the uncompressed asset is served; that is expected, not a bug.

Page components stay lazy — the Inertia Vite plugin splits one chunk per page, and `Vite::prefetch()` warms the other guest pages after load — and images are `loading="lazy" decoding="async"`.

## A spinner that never goes away means the bundle threw
The boot loader hides only once React renders into `#app`, so any uncaught error on first render leaves the guest app spinning for ever rather than showing an error. Read `storage/logs/browser.log` first.

The usual cause is a stale `public/build` after a shared prop was renamed on the server: the September build still read `props.restaurant` after it became `tenant`, so `restaurant.slug` threw on every load. `npm run build` (or `npm run dev`) fixes it, and also regenerates the Wayfinder files, which bake `APP_DOMAIN` into every tenant route URL — rebuild after changing the domain too.

## A stale public/hot file points the whole site at a dead Vite server
Laravel's Vite integration decides whether to emit dev-server script tags (`https://hospitality.test:5173/...`) purely by checking whether `public/hot` exists — never by checking that anything is actually listening on that port. `npm run dev` / `composer dev` writes it on start and removes it on a clean exit, but a killed or crashed dev server (Ctrl+C sometimes doesn't clean it up, a terminal closed mid-session always doesn't) leaves it behind.

Symptom: pages load fine in a browser with the dev server running, but `curl`, `php artisan test`, or a browser after the dev server is gone all get dev-server URLs that resolve to nothing — `GuestAppTest`'s "loads only its own entry and page" fails with the built manifest hash missing from the HTML entirely. Fix is `rm public/hot`, not a rebuild. If `npm run build` was run afterward, the manifest is already correct — the hot file was the only thing lying.

## vite is an alias of vite-plus-core; Node is pinned, npm is not
vite-plus 0.3.1 refuses to build unless `vite` resolves to its own core, so `package.json` declares `"vite": "npm:@voidzero-dev/vite-plus-core@<version>"` in `dependencies` **and** in `overrides`, as `vp migrate` wrote it. Without the override, `npm update` installs the real `vite` and `vp build` fails with "Expected @voidzero-dev/vite-plus-core". Bump both together with `vite-plus`.

**Node is pinned to 24 and nothing else.** `package.json` declares `"engines": {"node": "~24"}`, `.npmrc` carries `engine-strict=true` so npm refuses rather than warns, and `.nvmrc` holds the bare `24` — the one place the version is written, which CI's setup action reads through `node-version-file`. A clean install of the whole tree passes under Node 24 with engine-strict on; that was checked, because engine-strict applies to dependencies' own `engines` too, not just this package's.

**`devEngines.packageManager` stays out.** `vp migrate` writes a pin to npm 12. It was taken out, because Node 24 — locally and in CI — ships npm 11, and the pin makes every npm command refuse to run. Take it out again if a later `vp migrate` puts it back. Pinning `engines.node` is not the same thing and does not bring the problem back.
