<?php

namespace App\Enums;

/**
 * The outcome of asking for a sign-in code.
 *
 * Every message here is deliberately the same whether or not an account exists
 * at the address, so the sign-in page gives nothing away.
 */
enum OtpRequestOutcome: string
{
    case Sent = 'sent';

    /** Asked again too soon after the last code. */
    case Cooldown = 'cooldown';

    /** Used up the allowance of codes for this window. */
    case LimitReached = 'limit-reached';

    /**
     * Whether a code may actually be issued.
     */
    public function mayIssueCode(): bool
    {
        return $this === self::Sent;
    }

    /**
     * The message to show for this outcome.
     *
     * A cooldown names the seconds actually left when it is given them.
     */
    public function message(?int $secondsLeft = null): string
    {
        return match ($this) {
            self::Sent => 'If that address has an account, a sign-in code is on its way.',
            self::Cooldown => sprintf(
                'Wait %d seconds before asking for another code.',
                $secondsLeft ?? (int) config('otp.resend_cooldown'),
            ),
            self::LimitReached => sprintf(
                'That is %d codes for one address. Try again later.',
                (int) config('otp.max_sends'),
            ),
        };
    }
}
