<?php

namespace App\Actions\OneTimePasswords;

use App\Enums\OtpRequestOutcome;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Decides whether an address may be sent another sign-in code, and records the
 * request when it may.
 *
 * The limit is keyed on the address rather than on an account, so someone
 * probing the sign-in page cannot tell a real address from an unknown one by
 * watching which requests get throttled.
 */
class ThrottleOneTimePasswordRequests
{
    public function __invoke(string $email): OtpRequestOutcome
    {
        $key = $this->keyFor($email);

        if (RateLimiter::tooManyAttempts($key.':cooldown', maxAttempts: 1)) {
            return OtpRequestOutcome::Cooldown;
        }

        if (RateLimiter::tooManyAttempts($key.':quota', (int) config('otp.max_sends'))) {
            return OtpRequestOutcome::LimitReached;
        }

        RateLimiter::hit($key.':cooldown', (int) config('otp.resend_cooldown'));
        RateLimiter::hit($key.':quota', (int) config('otp.send_window'));

        return OtpRequestOutcome::Sent;
    }

    /**
     * Hash the address so the cache never holds one in the clear.
     */
    private function keyFor(string $email): string
    {
        return 'otp-request:'.sha1(mb_strtolower(trim($email)));
    }
}
