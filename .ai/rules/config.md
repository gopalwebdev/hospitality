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

Pinned by `tests/Feature/AppDomainTest.php`, which evaluates `config/app.php` off disk under each environment combination — `config('app.domain')` alone cannot test this, since the container resolved it once at boot from `phpunit.xml`, which pins both variables.
