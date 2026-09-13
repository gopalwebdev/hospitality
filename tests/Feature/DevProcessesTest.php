<?php

/*
|--------------------------------------------------------------------------
| php artisan dev
|--------------------------------------------------------------------------
|
| Herd serves the site at its own domain, so the built-in server stays out,
| and Horizon runs the queue, so the plain queue listener stays out too.
|
*/

it('does not start the built-in server because Herd serves the site', function (): void {
    $this->artisan('dev:list')
        ->expectsOutputToContain('php artisan horizon')
        ->doesntExpectOutputToContain('php artisan serve')
        ->assertSuccessful();
});

it('does not start a queue listener beside Horizon', function (): void {
    $this->artisan('dev:list')
        ->expectsOutputToContain('php artisan horizon')
        ->doesntExpectOutputToContain('queue:listen')
        ->assertSuccessful();
});
