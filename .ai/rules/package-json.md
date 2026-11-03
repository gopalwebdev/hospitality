---
paths:
  - package.json
---

# Package Json

## fontaine is imported by nothing and must stay
`fontaine` is a devDependency that **no source file imports**. Do not remove it as unused.

`laravel-vite-plugin/fonts` looks for it at build time and silently enables optimized font fallbacks when it is present. For the `bunny('Instrument Sans')` entry in `vite.config.ts` that means a generated `@font-face` named `Instrument Sans fallback` carrying `ascent-override: 93.49%`, `descent-override: 24.09%` and `size-adjust: 103.76%` — metrics matched to the real face so text does not reflow when the webfont swaps in. Verify with `grep size-adjust public/build/assets/fonts-*.css` after a build.

Remove it and nothing breaks loudly: the build still succeeds, the fallback rules just vanish and layout shift comes back on first paint, with one advisory line in the build log ("Optimized font fallbacks require the optional fontaine package") as the only signal. That line turned every Laravel Cloud deploy orange, which is how it was found.

It is build-time only — no bytes reach the browser beyond the few hundred extra in the fonts CSS. The alternative, if it ever has to go, is `optimizedFallbacks: false` on the `bunny()` call, which is a deliberate choice to accept the shift rather than a cleanup.
