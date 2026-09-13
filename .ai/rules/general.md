---
paths:
  - '**'
---

# General

## Update .ai/rules in the same change that makes them true
Standing instruction from the project owner: never finish a piece of work with the rules describing the old behaviour.

When a change alters a settled decision, a constraint, or a trap that `.ai/rules` records, update the affected rule file **in the same change** — not in a follow-up. When it establishes something new and durable, add it with `record-rule`. When it makes a rule wrong, rewrite that rule rather than leaving it to be discovered as stale.

A rule that has gone stale is worse than no rule: the next agent trusts it. Real examples from this repo — role permissions became editable while a rule still said they were read-only, and staff moved to a React phone app while a rule still called Filament panels "staff tools".

Check this before reporting work as done, every time.

## Standing conventions the project owner set
These are house rules, not preferences to be re-litigated:

- **Asia/Kolkata, from the environment.** `APP_TIMEZONE` and `DB_TIMEZONE` are set in `.env`; `config/app.php` and the pgsql connection read them. Never hardcode a timezone or a UTC offset in code.
- **`CarbonImmutable` everywhere.** `AppServiceProvider` calls `Date::use(CarbonImmutable::class)`, so model docblocks say `CarbonImmutable`, not `Carbon`. A date that appears to mutate in place is a bug.
- **One migration per table.** A change touching two tables is two migrations, named for the table each one touches. `translate_menu_category_names` and `translate_menu_item_names_and_descriptions` are one change split this way.
- **Spend as little memory in PHP as possible.** Name the columns a query needs rather than selecting everything, walk rows in chunks rather than loading them (`chunkById`, not `get()`, in migrations and commands), and hand raw values to the client rather than building strings per row.
- **Format in React, decide in PHP.** Money, dates and numbers are formatted client-side — prices cross the wire as integers and `resources/js/lib/money.ts` turns them into money in the reader's own language. Anything that is security-relevant or a decision — authorisation, validation, what a guest may see, what a tile points at — stays in PHP.
- **Delete what is no longer used.** A dead column, an unread helper or a leftover starter-kit file is worse than none: the next reader has to work out whether it matters.
- **PostgreSQL only.** Development, the test suite and CI all run Postgres; there is no SQLite connection and no driver branching. Use Postgres where it states a rule better than PHP can — `jsonb`, CHECK constraints, expression and partial indexes, `ilike`. See `.ai/rules/config.md` and `.ai/rules/migrations.md`.
- **No N+1s and no duplicate queries.** Local and the test suite throw on both (`.ai/rules/app.md`). Fix the cause; do not widen the guard to make a page pass.

## The staff app has been removed; the guest app and the two panels are the product
Standing scope decision from the project owner: the staff PWA — its pages, entry, root template, `staff.*` routes, sign-in flow and `lang/en/staff.php` — was deleted outright rather than parked. What remains is the guest app (the one Inertia surface, itself an installable PWA) and the two Filament panels.

The **Staff role** is untouched: it is a Spatie role with a per-tenant limit (`tenants.max_staff`), and accounts still hold it. It simply has no surface of its own until a staff app comes back. A feature described for "the app" means the guest app.
