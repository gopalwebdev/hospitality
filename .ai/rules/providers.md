---
paths:
  - app/Providers/AppServiceProvider.php
---

# Providers

## DevCommands::except() replaces the list, so restate Horizon's queue exclusion
`configureDevProcesses()` calls `DevCommands::except('server', 'queue')`. `except()` overwrites whatever was excluded before, and Horizon's provider has already called `except('queue')` in its `register()` by the time ours boots — dropping `queue` from our call silently brings `queue:listen` back into `php artisan dev`, running beside Horizon's workers. `server` is out because Herd serves the site (`.ai/rules/routes.md`). `tests/Feature/DevProcessesTest.php` pins both.
