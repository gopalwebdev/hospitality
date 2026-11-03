---
paths:
  - '.github/workflows/**'
---

# Workflows

## CI is a chain: lint/check/format -> tests -> deploy
`ci.yml` runs in three stages, and the shape is deliberate:

1. `code-style`, `static-analysis` and `frontend` in parallel — the cheap checks.
2. `tests`, which `needs` all three. It is the slowest job and the only one that needs Postgres and a built bundle, so a branch that fails formatting or a type check never pays for either.
3. `deploy-staging`, which `needs: tests`.

`needs` defaults to `if: success()`, so anything red anywhere up the chain stops the deploy. The gate is transitive rather than a flat list: `deploy-staging` names only `tests`, and `tests` names the other three.

**That transitivity is the trap.** A new job added to stage 1 must be added to the `tests` `needs` list, or it guards nothing — the deploy sails past it and nothing fails to tell you. If a new job belongs after the suite instead, `deploy-staging` has to name it too.

The one job deliberately outside the chain is `report` (below), which gates nothing.

The cost of the chain is wall-clock on the happy path: the stages run back to back rather than overlapping, so time-to-deploy is roughly stage 1's slowest job plus the suite. `static-analysis` is about as slow as `tests`, so this is close to double what a flat fan-out would take. That was accepted knowingly — runner minutes on red branches matter more here than minutes to staging.

`deploy-staging` POSTs to a Laravel Cloud deploy hook. It is the only way staging is deployed — there is no push-to-deploy wired on the Cloud side.

The hook URL is an *environment* secret, `LARAVEL_CLOUD_STAGING_DEPLOY_HOOK`, on the GitHub environment named `staging`. The job carries `environment: staging` for that reason; without it the secret resolves to an empty string. The step fails loudly on an empty value rather than curling a bare `?commit_hash=` URL.

The request is `curl -X POST "$DEPLOY_HOOK?commit_hash=$GITHUB_SHA"`. The `commit_hash` pins the deploy to the commit CI just proved green rather than the branch tip at pull time; the commit must belong to the environment's configured branch. No `--location`: the URL is a credential and must not be re-sent to a redirect target. No checkout step — the job needs the SHA and nothing from the tree.

## Only the newest commit on a ref is ever checked, main included
Workflow-level `concurrency: {group: ci-${{ github.ref }}, cancel-in-progress: true}`. Pushing again to a branch cancels the run already in flight.

This applies to main too, and that is a deliberate trade: a superseded commit on main is recorded as **cancelled rather than green or red**, so main's history does not carry a result for every commit. What it buys is that the commit on staging is the one at the tip of main, not whichever run happened to finish last. Do not re-exempt main without understanding that it reintroduces that race.

Job-level `concurrency: {group: deploy-staging, cancel-in-progress: false}` stays on top of this because cancellation is not instantaneous: a deploy that has already fired keeps going for a moment while the next run catches up. The job-level group serialises that window so the two hooks fire one after another and the newer commit lands last.

## The `report` job is the one shared summary
`vite-plus` writes its own "Vitest Test Report" into the frontend job's summary, which covered only that job. The `report` job exists so a run has one TL;DR: a table of every job's result, the two suites' counts, and whether staging deployed.

It `needs` every job and is `if: always()`, so it describes a red or cancelled run as well as a green one. It gates nothing and must stay out of the deploy chain — it runs *after* the deploy has been decided, so making anything depend on it would deadlock.

The counts reach it through job outputs: `frontend` and `tests` each tee their suite's output to `$RUNNER_TEMP`, then a `Collect the ... totals` step with `if: always()` greps it and writes `$GITHUB_OUTPUT`. Notes on that, learned the hard way:

- The tee'd command runs under `set -o pipefail`, or a failing suite would be masked by `tee` succeeding.
- The collect step is `always()` so a *failing* suite still reports its counts rather than leaving a blank cell.
- Output is stripped of ANSI escapes first, or they land in the markdown table verbatim.
- The greps target Pest's `Tests:    3 passed (11 assertions)` / `Duration: 0.51s` and Vitest's `Test Files  9 passed (9)` / `Tests  64 passed (64)`. **Verify against real output before changing them.** Running Pest inside an AI coding agent is misleading: Claude Code's environment variables switch Pest 5 to a single-line JSON reporter, which CI never sees. Reproduce CI's output with a cleared environment (`env -i PATH=... HOME=...`).

Action SHAs in this directory are Dependabot's job (weekly, grouped). Do not bump them by hand, with one exception noted below.

## The runner image is pinned, and unpinning it is coupled to setup-php
Every job is `runs-on: ubuntu-24.04`, never `ubuntu-latest`. Two reasons, and the second is the binding one:

- `ubuntu-latest` is migrating to Ubuntu 26 between **19 October and 19 November 2026**, handing out either image while the rollout runs. A green run should mean the same thing on every commit, and that matters more now that a green run deploys.
- The pinned `shivammathur/setup-php` is **2.37.2** (June 2026). Ubuntu 26.04 support arrived in **2.40.0** (8 October 2026). On 26.04 the pinned action is untested at best.

So moving to `ubuntu-26.04` is **two changes in one**: bump `shivammathur/setup-php` in `.github/actions/setup/action.yml` to 2.40.0 or later *first*, then change the five `runs-on` lines. Doing the second without the first breaks every job at the PHP setup step.

This is the one case where bumping an action SHA by hand is right rather than waiting for Dependabot, because the bump is a prerequisite for the image move rather than routine maintenance.

Ubuntu 26.04 ships PHP 8.5 and Node 24 as its system versions, which happen to match this project's pins — but nothing here relies on the system versions. `setup-php`, `setup-node` reading `.nvmrc`, and the `postgres:18` service container all override them, so the image move should be a non-event once the action is current.
