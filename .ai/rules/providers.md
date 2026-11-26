---
paths:
  - app/Providers/AppServiceProvider.php
---

# Providers

## DevCommands::except() leaves out the server and the queue listener
`configureDevProcesses()` calls `DevCommands::except('server', 'queue')`, so `php artisan dev` runs Pail and Vite and nothing else. `server` is out because Herd serves the site (`.ai/rules/routes.md`). `queue` is out because there is no queue: Horizon was removed and both notifications send inline, so a listener would sit on `sync` with nothing to take (`.ai/rules/config.md`). `except()` overwrites the list rather than adding to it, so a process that must stay out has to be named in this one call. `tests/Feature/DevProcessesTest.php` pins both halves.

## No time picker is configured here
`configureTimePickers()` and its `TimePicker::configureUsing()` went with the last `TimePicker`. Every time of day in a panel is now `App\Filament\Forms\Components\ClockTimePicker` (`.ai/rules/filament.md`), which needs no set-up here.

A `DatePicker` or `DateTimePicker` added later still needs its own `configureUsing()` here, if it is to be set once. `configureUsing()` is keyed by the exact class.
