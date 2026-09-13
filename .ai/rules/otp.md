---
paths:
  - 'app/Actions/Otp/**'
---

# Otp

## Sign-in codes are emailed through the queue
SendOneTimePassword sends `SignInCodeNotification`, a `ShouldQueue` notification pinned to the `mail` queue, which Horizon works ahead of `default`. A code that arrives after it expires is useless, so never move mail behind other work.

`otp.log_codes` additionally writes the code in the clear to the `otp` log channel. It defaults to **off** and exists only for local development — enabling it anywhere real is a credential leak. Tests turn it on through the `captureIssuedCodes()` helper in tests/Pest.php.

Only a bcrypt hash of the code is stored. Issuing a new code retires any outstanding one for that user, and each code tolerates `otp.max_attempts` wrong guesses before it burns.

## The panel says when an address has no account — deliberately
`requestCode()` refuses an address with no usable account, with a validation error, before any code is issued. This is a knowing trade: it tells an attacker which addresses are real, and it is accepted because these are two small internal panels where the UX of "there is no account for that email address" beats silence.

Do not "fix" this back to a uniform response without asking. If this ever fronts public sign-up, revisit it.

## Request limits still key on the address, not the account
ThrottleOneTimePasswordRequests allows `otp.max_sends` codes per `otp.send_window` seconds, at least `otp.resend_cooldown` seconds apart, keyed on a hash of the address. Keying on the address rather than the user id means the limit survives an account being deleted and recreated, and it applies before any code is issued.

## Local development accepts any sign-in code (temporary)
On the project owner's instruction, `VerifyOneTimePassword` returns Verified for any code when `app()->isLocal()`, retiring any outstanding code, so signing in during development needs no email. Every other environment checks the code as before — `testing` included, so the suite still exercises real verification, and a test pins the local bypass on its own. It is a stopgap until OTP is fixed: remove it then, and never widen it beyond the local environment.
