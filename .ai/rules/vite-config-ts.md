---
paths:
  - vite.config.ts
---

# Vite Config Ts

## Rolldown's pluginTimings check is off on purpose
`build.rollupOptions.checks.pluginTimings` is `false`. Do not turn it back on expecting it to tell you something.

Rolldown warns when plugin hooks dominate a build. Here they always will. The bundling itself is Rust and finishes in well under a second, so the split is ~98% plugins on any machine, and the figure is a **share, not a budget** — it cannot fall as the build gets faster. The two hooks it names are real work nothing can skip: `@laravel/vite-plugin-wayfinder`'s `buildStart` shells out to `php artisan wayfinder:generate`, and `@rolldown/plugin-babel` runs the React Compiler preset over every component.

A check that can only ever fire is noise. Left on, it labelled every Laravel Cloud deploy "Finished with warnings" (the build itself succeeded — the orange `[PLUGIN_TIMINGS]` line was the whole cause), which trains you to ignore the one place a warning worth reading would appear.

It does not reproduce locally on a warm machine: the build lands near 1.7s and the check stays quiet. To see it, add a plugin with a `buildStart` that sleeps ~3s. That is also how to verify the flag still suppresses it after a `vite-plus` upgrade — a build that is merely fast proves nothing.

The other `ChecksOptions` entries are untouched and still on.
