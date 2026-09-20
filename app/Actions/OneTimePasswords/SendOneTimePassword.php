<?php

namespace App\Actions\OneTimePasswords;

use App\Models\OneTimePassword;
use App\Models\User;
use App\Notifications\SignInCodeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Issues a fresh sign-in code for a user and delivers it.
 *
 * Delivery is a queued email. While developing, otp.log_codes also writes the
 * code to its own log channel so it can be read straight out of
 * storage/logs/otp.log without waiting on a mailbox.
 */
class SendOneTimePassword
{
    public function __invoke(User $user): OneTimePassword
    {
        // Retire anything still outstanding so only the newest code can be
        // used. Otherwise every code ever requested stays live until it expires.
        $user->oneTimePasswords()
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();

        $oneTimePassword = $user->oneTimePasswords()->create([
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('otp.ttl')),
        ]);

        $this->deliver($user, $oneTimePassword, $code);

        return $oneTimePassword;
    }

    /**
     * Build a zero-padded numeric code of the configured length.
     */
    private function generateCode(): string
    {
        $length = (int) config('otp.length');

        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * Send the code to the person signing in.
     */
    private function deliver(User $user, OneTimePassword $oneTimePassword, string $code): void
    {
        $user->notify(new SignInCodeNotification($code, (int) config('otp.ttl')));

        if (! config('otp.log_codes')) {
            return;
        }

        Log::channel((string) config('otp.log_channel'))->info('Sign-in code issued.', [
            'user_id' => $user->getKey(),
            'email' => $user->email,
            'code' => $code,
            'expires_at' => $oneTimePassword->expires_at->toIso8601String(),
        ]);
    }
}
