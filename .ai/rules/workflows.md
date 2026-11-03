---
paths:
  - '.github/workflows/**'
---

# Workflows

## Staging deploys are a CI job gated on every other job
`ci.yml` ends with a `deploy-staging` job that POSTs to a Laravel Cloud deploy hook. It is the only way staging is deployed — there is no push-to-deploy wired on the Cloud side.

The gate is `needs: [code-style, static-analysis, frontend, tests]`. `needs` defaults to `if: success()`, so a red job anywhere stops the deploy. **Adding a new CI job means adding it to that `needs` list** — a job left out is a check that no longer guards staging, and nothing fails to tell you.

The hook URL is an *environment* secret, `LARAVEL_CLOUD_STAGING_DEPLOY_HOOK`, on the GitHub environment named `staging`. The job carries `environment: staging` for that reason; without it the secret resolves to an empty string. The step fails loudly on an empty value rather than curling a bare `?commit_hash=` URL.

The request is `curl -X POST "$DEPLOY_HOOK?commit_hash=$GITHUB_SHA"`. The `commit_hash` pins the deploy to the commit CI just proved green rather than the branch tip at pull time; the commit must belong to the environment's configured branch. No `--location`: the URL is a credential and must not be re-sent to a redirect target. No checkout step — the job needs the SHA and nothing from the tree.

Job-level `concurrency: {group: deploy-staging, cancel-in-progress: false}` serialises deploys. The workflow-level group deliberately does not cancel runs on main, so two close commits both run CI to the end and would otherwise race; queueing lets the newer of two waiting commits supersede the older.

Action SHAs in this directory are Dependabot's job (weekly, grouped). Do not bump them by hand.
