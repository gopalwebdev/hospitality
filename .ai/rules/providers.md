---
paths:
  - app/Providers/AppServiceProvider.php
---

# Providers

## DevCommands::except() replaces the list, so restate Horizon's queue exclusion
`configureDevProcesses()` calls `DevCommands::except('server', 'queue')`. `except()` overwrites whatever was excluded before, and Horizon's provider has already called `except('queue')` in its `register()` by the time ours boots — dropping `queue` from our call silently brings `queue:listen` back into `php artisan dev`, running beside Horizon's workers. `server` is out because Herd serves the site (`.ai/rules/routes.md`). `tests/Feature/DevProcessesTest.php` pins both.

## No time picker is configured here
`configureTimePickers()` and its `TimePicker::configureUsing()` went with the last `TimePicker`. Every time of day in a panel is now `App\Filament\Forms\Components\ClockTimePicker` (`.ai/rules/filament.md`), which needs no set-up here.

A `DatePicker` or `DateTimePicker` added later still needs its own `configureUsing()` here, if it is to be set once. `configureUsing()` is keyed by the exact class.
