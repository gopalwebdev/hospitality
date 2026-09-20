<?php

use App\Actions\OneTimePasswords\ThrottleOneTimePasswordRequests;
use App\Enums\OtpRequestOutcome;

/**
 * The limit is keyed on the address, never on an account, so that a throttled
 * request tells an attacker nothing about whether the address is real.
 */
function requestCodeFor(string $email): OtpRequestOutcome
{
    return app(ThrottleOneTimePasswordRequests::class)($email);
}

it('lets the first request through', function (): void {
    expect(requestCodeFor('someone@example.com'))->toBe(OtpRequestOutcome::Sent);
});

it('holds the next request until the cooldown passes', function (): void {
    requestCodeFor('someone@example.com');

    expect(requestCodeFor('someone@example.com'))->toBe(OtpRequestOutcome::Cooldown);

    $this->travel((int) config('otp.resend_cooldown') + 1)->seconds();

    expect(requestCodeFor('someone@example.com'))->toBe(OtpRequestOutcome::Sent);
});

it('stops after the configured number of codes', function (): void {
    $maxSends = (int) config('otp.max_sends');

    for ($request = 1; $request <= $maxSends; $request++) {
        expect(requestCodeFor('someone@example.com'))->toBe(OtpRequestOutcome::Sent);
        $this->travel((int) config('otp.resend_cooldown') + 1)->seconds();
    }

    expect(requestCodeFor('someone@example.com'))->toBe(OtpRequestOutcome::LimitReached);
});

it('lets the address ask again once the window has rolled over', function (): void {
    $maxSends = (int) config('otp.max_sends');

    for ($request = 1; $request <= $maxSends; $request++) {
        requestCodeFor('someone@example.com');
        $this->travel((int) config('otp.resend_cooldown') + 1)->seconds();
    }

    expect(requestCodeFor('someone@example.com'))->toBe(OtpRequestOutcome::LimitReached);

    $this->travel((int) config('otp.send_window') + 1)->seconds();

    expect(requestCodeFor('someone@example.com'))->toBe(OtpRequestOutcome::Sent);
});

it('counts each address separately', function (): void {
    requestCodeFor('someone@example.com');

    expect(requestCodeFor('someone-else@example.com'))->toBe(OtpRequestOutcome::Sent);
});

it('treats an address as the same however it is capitalised or spaced', function (): void {
    requestCodeFor('someone@example.com');

    expect(requestCodeFor('  SomeOne@Example.COM  '))->toBe(OtpRequestOutcome::Cooldown);
});

it('says nothing about whether an account exists', function (): void {
    // An unknown address is throttled on exactly the same schedule as a real
    // one, so the response cannot be used to enumerate accounts.
    requestCodeFor('nobody@example.com');

    expect(requestCodeFor('nobody@example.com'))->toBe(OtpRequestOutcome::Cooldown);
});
