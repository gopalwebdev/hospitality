---
paths:
  - 'app/Actions/OneTimePasswords/**'
---

# Otp

## Sign-in codes are emailed inline
SendOneTimePassword sends `SignInCodeNotification` **inline**: it is a plain `Notification`, not a `ShouldQueue` one, so the mail goes out on the request that asked for the code. The project owner had the queue taken out altogether (`.ai/rules/config.md`) — a code waiting on a worker nobody is running is a sign-in that never happens, which is exactly how it failed. A code that arrives after it expires is useless, so do not put this back behind a queue without a worker running in every environment.

All mail, not only sign-in codes: local sends to Herd's mail server over SMTP (read it with `herd mail:latest`), and because sending is inline, asking for a code fails outright when that server is not running; staging and production set `MAIL_MAILER=brevo`, Brevo's HTTP API (`symfony/brevo-mailer`, registered in `AppServiceProvider::configureMailTransports()`, key in `BREVO_API_KEY`) — the key is never committed, and `MAIL_FROM_ADDRESS` must be a sender verified in Brevo. Brevo refuses calls from IPs not authorised on the account (`Unauthorized IP address`), so a new server needs its IP allowed there, or the restriction turned off.

`otp.log_codes` additionally writes the code in the clear to the `otp` log channel. It defaults to **off** and is off in `.env` too, at the project owner's instruction: codes are not to be written to a log. Enabling it anywhere real is a credential leak. Tests turn it on through the `captureIssuedCodes()` helper in tests/Pest.php, which reads the codes out of a file of its own.

Only a bcrypt hash of the code is stored. Issuing a new code retires any outstanding one for that user, and each code tolerates `otp.max_attempts` wrong guesses before it burns.

## The panel says when an address has no account — deliberately
`requestCode()` refuses an address with no usable account, with a validation error, before any code is issued. This is a knowing trade: it tells an attacker which addresses are real, and it is accepted because these are two small internal panels where the UX of "there is no account for that email address" beats silence.

Do not "fix" this back to a uniform response without asking. If this ever fronts public sign-up, revisit it.

## Request limits still key on the address, not the account
ThrottleOneTimePasswordRequests allows `otp.max_sends` codes per `otp.send_window` seconds, at least `otp.resend_cooldown` seconds apart, keyed on a hash of the address. The defaults are **20 per 600 seconds, 120 apart**; the allowance was widened from 3 per 1800 while sign-in email is being sorted out, and is due to be tightened again. At 120 seconds apart only five codes fit in 600 seconds, so `max_sends` does not bite until it is lowered or the window widened. The 120-second gap is also what the sign-in page shows: once a code is out, the email field is disabled and "Send a new code" is replaced by a browser-side countdown until `otp.resend_cooldown` has passed (`OtpLogin::secondsUntilResend()`), so the link never offers a request the throttle would refuse. Asking again from the address step inside the cooldown does not dead-end on "Hold on": if the account still holds a live code with attempts left, `OtpLogin::resumeOutstandingCode()` returns to the code step for it, with the countdown measured from when it was sent; only when there is no usable code does it say "Hold on", naming the seconds actually left (`ThrottleOneTimePasswordRequests::secondsUntilNextRequest()`). The form's button row is keyed on both the step and whether the code is complete, because a button patched in place keeps a stale `aria-disabled="true"` and Filament swallows every click on it. Filament's own `rateLimit(5)` on the login page sits in front of this one and is the limit a tight loop hits first; `tests/Feature/Auth/OtpLoginTest.php` pins its own numbers rather than reading these, for that reason. Keying on the address rather than the user id means the limit survives an account being deleted and recreated, and it applies before any code is issued.

## Only the code that was issued signs anyone in, in every environment
On the project owner's instruction there is **no master code and no bypass**: a hard-coded `020198` that signed anybody in, and a local-only branch that accepted any code at all, were both removed. `VerifyOneTimePassword` checks the newest outstanding code and nothing else, locally included — read a local code with `herd mail:latest`. Do not bring back a master code, an environment-driven bypass or a "just for staging" variant. Tests pin that `020198` is refused and that a wrong code is refused on a local machine.
