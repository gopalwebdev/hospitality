<?php

namespace App\Actions\OneTimePasswords;

use App\Enums\OtpVerificationResult;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Checks a submitted sign-in code against the newest code issued to a user.
 *
 * A correct code is consumed on the spot so it can never be replayed, and a
 * wrong one costs the code one of its attempts.
 */
class VerifyOneTimePassword
{
    public function __invoke(User $user, string $code): OtpVerificationResult
    {
        $oneTimePassword = $user->oneTimePasswords()
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($oneTimePassword === null) {
            return OtpVerificationResult::NotFound;
        }

        if ($oneTimePassword->hasExpired()) {
            return OtpVerificationResult::Expired;
        }

        if ($oneTimePassword->hasExhaustedAttempts()) {
            return OtpVerificationResult::TooManyAttempts;
        }

        if (! Hash::check($code, $oneTimePassword->code_hash)) {
            $oneTimePassword->increment('attempts');

            // Burning the last attempt retires the code outright, so a fresh
            // one has to be requested rather than guessed at again.
            return $oneTimePassword->hasExhaustedAttempts()
                ? OtpVerificationResult::TooManyAttempts
                : OtpVerificationResult::Incorrect;
        }

        $oneTimePassword->forceFill(['consumed_at' => now()])->save();

        return OtpVerificationResult::Verified;
    }
}
