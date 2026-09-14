---
paths:
  - app/Providers/AppServiceProvider.php
---

# Providers

## DevCommands::except() replaces the list, so restate Horizon's queue exclusion
`configureDevProcesses()` calls `DevCommands::except('server', 'queue')`. `except()` overwrites whatever was excluded before, and Horizon's provider has already called `except('queue')` in its `register()` by the time ours boots — dropping `queue` from our call silently brings `queue:listen` back into `php artisan dev`, running beside Horizon's workers. `server` is out because Herd serves the site (`.ai/rules/routes.md`). `tests/Feature/DevProcessesTest.php` pins both.

## Every time picker is Filament's, configured once
`configureTimePickers()` sets `TimePicker::configureUsing()` to Filament's JavaScript picker (`native(false)`), no seconds, a `h:i A` display and a clock icon. The browser's native time input drew an unstyled dropdown that looked different in every browser, and the project owner asked for one modern picker everywhere. Forms therefore do not repeat these settings. `configureUsing()` is keyed by the exact class, so a `DatePicker` or `DateTimePicker` added later needs its own line here rather than inheriting this one.
