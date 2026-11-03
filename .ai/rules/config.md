---
paths:
  - 'config/**'
  - config/tenants.php
---

# Config

## Valkey backs cache, session and queue; Horizon runs the workers
Valkey is Redis wire-compatible, so `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` are all `redis` against 127.0.0.1:6379 via phpredis. There is no separate Valkey driver — do not go looking for one.

Queued work needs `php artisan horizon` running; nothing is delivered without it. Supervisor queues are `['mail', 'default']` in that order, and `/horizon` is gated on the product team flag (`User::isAdmin()`) in HorizonServiceProvider.

phpunit.xml pins tests to array cache/session and the sync queue, so tests never touch Valkey. If tests start behaving as though they share state, check for a stale `bootstrap/cache/config.php` — a cached config silently overrides every phpunit.xml env var. `php artisan optimize:clear` fixes it, and nothing in local development should leave those caches in place.

## Postgres is the only database, tests included
`config/database.php` has one connection, `pgsql`, and it is the default. `phpunit.xml` pins only the connection, a `hospitality_testing` database and `DB_TIMEZONE`; host, port and user come from `.env`, so the suite runs against the same local server as development. CI runs a `postgres:18` service with the same database name. Create it once locally against Herd's Postgres (`createdb hospitality_testing`, or `create database hospitality_testing` in psql).

There is no SQLite anywhere — no connection, no `database/database.sqlite`, no driver branching in migrations, no `pdo_sqlite` in CI — so write Postgres: `jsonb`, CHECK constraints, `ilike`. The suite running on the production engine is also what finally catches the Postgres-only failures that used to surface first in production.

## DB_TIMEZONE must be a named zone, never an offset
The application runs in Asia/Kolkata, set from `APP_TIMEZONE` in .env, and the pgsql connection is given the same zone through `DB_TIMEZONE` so `now()` in PHP and `now()` in SQL agree. phpunit.xml pins both of them, so a run without a .env — CI, a fresh checkout — keeps the two clocks together rather than falling back on the config defaults.

The trap: it has to be the named zone, not an offset. Laravel issues `SET TIME ZONE '<value>'`, and Postgres reads a bare `+05:30` with POSIX sign conventions — positive means *west* of Greenwich — so it silently lands on UTC-5:30, eleven hours out, with no error. Verified: `+05:30` gives `00:50:05-05:30` where `Asia/Kolkata` gives `11:50:05+05:30`.

Never hardcode a zone or an offset in code; both come from the environment.

## config/tenants.php holds only the default role limits
default_max_owners and default_max_staff seed a newly created tenant's max_owners/max_staff columns (see .ai/rules/app.md's "A tenant caps its own owners and staff"). They are not read anywhere after a tenant is created — TenantForm's create-form default() reads them, and the migration's column default() does too, but every existing tenant's actual limit lives on its own row, edited from TenantForm. Do not add a runtime read of this config expecting it to reflect an existing tenant's current limit.

## app.domain falls back to APP_URL's host, never a literal
`config('app.domain')` is the bare host the platform panel is bound to (`PlatformPanelProvider::domain()`) and the base every tenant subdomain hangs off (`TenantPanelProvider::tenantDomain()`, `routes/tenant.php`, `TenantForm`, `EditTenant`). A wrong value does not fail loudly — it silently generates every link and every post-sign-in redirect against the wrong host.

It used to read `env('APP_DOMAIN', 'hospitality.test')`. A Laravel Cloud environment injects `APP_URL` but not `APP_DOMAIN`, so staging served the panel bound to `hospitality.test`: signing in at `gopal-staging.laravel.cloud` redirected to the developer's local Herd domain. It now resolves `APP_DOMAIN`, then `APP_URL`'s host, then `localhost`. **Never reintroduce a literal domain as the fallback.**

`parse_url(..., PHP_URL_HOST)` is deliberate: it strips the scheme and the port, which is what "bare host" means here. An empty `APP_DOMAIN` is treated as unset.

Two consequences worth knowing:

- The value is baked into the **frontend bundle** too. Wayfinder writes `APP_DOMAIN` into every tenant route URL at generate time, so changing the domain means rebuilding, not just clearing config (`.ai/rules/js.md`).
- It is read at config-cache time. A deploy runs `php artisan optimize` (`composer deploy`), so a change to either variable needs a redeploy to take effect.

**Nothing pins this.** A test existed and was removed at the project owner's request, so a regression here is silent — the suite cannot catch it, because `phpunit.xml` pins both variables and `config('app.domain')` is resolved once at boot from them. Testing it means evaluating `config/app.php` off disk under a changed environment. Check the fallback by hand after touching this entry.

## Nightwatch needs an agent process; it is off everywhere except Cloud
`laravel/nightwatch` v1 is installed. It does **not** send telemetry anywhere on its own: the app writes events to a local agent over TCP (`NIGHTWATCH_INGEST_URI`, default `127.0.0.1:2407`), and that agent — `php artisan nightwatch:agent` — is what ships them. No agent, no data.

Where it is on, and where it is not:

- **Local**: off. `.env.example` sets `NIGHTWATCH_ENABLED=false` because nothing in `php artisan dev` starts an agent. Left on, every request would attempt a connection that is refused.
- **Tests**: off. `phpunit.xml` carries `<env name="NIGHTWATCH_ENABLED" value="false"/>`, which the Laravel skeleton ships alongside `PULSE_ENABLED` and `TELESCOPE_ENABLED`. Do not remove it.
- **Laravel Cloud**: on, and configured from the dashboard rather than from this repo. "Connect Nightwatch" on the environment injects `NIGHTWATCH_TOKEN`, `NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1`, `LOG_CHANNEL=stack` and `LOG_STACK=laravel-cloud-socket,nightwatch`, and runs the agent on every App and Worker cluster. Do not also add a manual background process for the agent — the docs warn to remove any manual ones first, or the environment runs two.

There is no `config/nightwatch.php`; the package is driven entirely by environment variables, so publishing one is not needed.

`php artisan nightwatch:status` is the health check and exits non-zero on error. Locally it reports `Unable to connect to tcp://127.0.0.1:2407 (Connection refused)`, which is expected, not a fault.

On Octane: the Nightwatch docs say nothing about Octane, FrankenPHP, Swoole or RoadRunner — there is no page for it. Verified by hand that the app boots and serves under `octane:start --server=frankenphp` with the package installed. Treat deeper Octane interaction as unverified rather than supported.
