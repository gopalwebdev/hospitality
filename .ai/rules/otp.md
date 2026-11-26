---
paths:
  - 'app/Actions/OneTimePasswords/**'
---

# Otp

## Sign-in codes are emailed inline
SendOneTimePassword sends `SignInCodeNotification` **inline**: it is a plain `Notification`, not a `ShouldQueue` one, so the mail goes out on the request that asked for the code. The project owner had the queue taken out altogether (`.ai/rules/config.md`) — a code waiting on a worker nobody is running is a sign-in that never happens, which is exactly how it failed. A code that arrives after it expires is useless, so do not put this back behind a queue without a worker running in every environment.

`otp.log_codes` additionally writes the code in the clear to the `otp` log channel. It defaults to **off** and is off in `.env` too, at the project owner's instruction: codes are not to be written to a log. Enabling it anywhere real is a credential leak. Tests turn it on through the `captureIssuedCodes()` helper in tests/Pest.php, which reads the codes out of a file of its own.

Only a bcrypt hash of the code is stored. Issuing a new code retires any outstanding one for that user, and each code tolerates `otp.max_attempts` wrong guesses before it burns.

## The panel says when an address has no account — deliberately
`requestCode()` refuses an address with no usable account, with a validation error, before any code is issued. This is a knowing trade: it tells an attacker which addresses are real, and it is accepted because these are two small internal panels where the UX of "there is no account for that email address" beats silence.

Do not "fix" this back to a uniform response without asking. If this ever fronts public sign-up, revisit it.

## Request limits still key on the address, not the account
ThrottleOneTimePasswordRequests allows `otp.max_sends` codes per `otp.send_window` seconds, at least `otp.resend_cooldown` seconds apart, keyed on a hash of the address. The defaults are **20 per 600 seconds, 5 apart**, widened from 3 per 1800 while sign-in email is being sorted out — tighten them again with the master code. Filament's own `rateLimit(5)` on the login page sits in front of this one and is the limit a tight loop hits first; `tests/Feature/Auth/OtpLoginTest.php` pins its own numbers rather than reading these, for that reason. Keying on the address rather than the user id means the limit survives an account being deleted and recreated, and it applies before any code is issued.

## 020198 signs anybody in, and local accepts any code (both temporary)
On the project owner's instruction, `VerifyOneTimePassword::MASTER_CODE` is the literal `020198`, and it returns Verified for **any user in any environment** — production included — whether or not a code was ever issued. It retires whatever code was outstanding, exactly as a real one would. Beside it, `app()->isLocal()` still accepts any code at all, so signing in during development needs no email.

Both are stopgaps while sign-in email is unreliable, and both are back doors: anyone who reads this repository can sign in as anyone. **Delete `MASTER_CODE` and its branch the moment codes can be relied on to arrive**, and do not widen it — no second code, no environment-driven list, no "just for staging" variant. `testing` is not exempt either: the suite exercises real verification everywhere else, and one test pins each stopgap on its own.
