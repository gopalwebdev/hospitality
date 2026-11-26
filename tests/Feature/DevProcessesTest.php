<?php

/*
|--------------------------------------------------------------------------
| php artisan dev
|--------------------------------------------------------------------------
|
| Herd serves the site at its own domain, so the built-in server stays out,
| and nothing in the application is queued, so the queue listener does too.
|
*/

it('does not start the built-in server because Herd serves the site', function (): void {
    $this->artisan('dev:list')
        ->expectsOutputToContain('npm run dev')
        ->doesntExpectOutputToContain('php artisan serve')
        ->assertSuccessful();
});

it('does not start a queue listener because nothing is queued', function (): void {
    $this->artisan('dev:list')
        ->expectsOutputToContain('npm run dev')
        ->doesntExpectOutputToContain('queue:listen')
        ->assertSuccessful();
});
