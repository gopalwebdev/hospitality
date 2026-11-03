<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Request;

/*
|--------------------------------------------------------------------------
| config('app.domain')
|--------------------------------------------------------------------------
|
| The platform panel is bound to this host and tenant routes hang off
| subdomains of it, so a wrong value sends every link and every
| post-sign-in redirect to the wrong place. It used to fall back to a
| literal development domain, which is what a Laravel Cloud environment
| setting APP_URL but not APP_DOMAIN got served.
|
| These read config/app.php back off disk rather than config('app.domain'),
| because the container resolved the value once at boot from phpunit.xml.
|
*/

/**
 * Evaluate config/app.php's `domain` entry under a given environment.
 *
 * @param  array<string, string|null>  $environment  null unsets the variable
 */
function domainUnderEnvironment(array $environment): string
{
    $restore = [];

    foreach ($environment as $key => $value) {
        $restore[$key] = Request::server($key) ?? null;

        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            continue;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    try {
        $config = require base_path('config/app.php');

        return $config['domain'];
    } finally {
        foreach ($restore as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }
}

it('prefers APP_DOMAIN when it is set', function (): void {
    expect(domainUnderEnvironment([
        'APP_DOMAIN' => 'hospitality.test',
        'APP_URL' => 'https://gopal-staging.laravel.cloud',
    ]))->toBe('hospitality.test');
});

it('falls back to the host of APP_URL rather than a development domain', function (): void {
    expect(domainUnderEnvironment([
        'APP_DOMAIN' => null,
        'APP_URL' => 'https://gopal-staging.laravel.cloud',
    ]))->toBe('gopal-staging.laravel.cloud');
});

it('treats an empty APP_DOMAIN as unset', function (): void {
    expect(domainUnderEnvironment([
        'APP_DOMAIN' => '',
        'APP_URL' => 'https://gopal-staging.laravel.cloud',
    ]))->toBe('gopal-staging.laravel.cloud');
});

it('drops the port, since the value is a bare host', function (): void {
    expect(domainUnderEnvironment([
        'APP_DOMAIN' => null,
        'APP_URL' => 'http://hospitality.test:8000',
    ]))->toBe('hospitality.test');
});

it('falls back to localhost when neither is set', function (): void {
    expect(domainUnderEnvironment([
        'APP_DOMAIN' => null,
        'APP_URL' => null,
    ]))->toBe('localhost');
});
