<?php

use App\Actions\OneTimePasswords\SendOneTimePassword;
use App\Actions\OneTimePasswords\VerifyOneTimePassword;
use App\Enums\OtpVerificationResult;
use App\Models\OneTimePassword;
use App\Models\User;
use App\Notifications\SignInCodeNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Issuing codes
|--------------------------------------------------------------------------
*/

it('writes the code it issues to the one-time password log', function (): void {
    $readCodes = captureIssuedCodes();
    $user = User::factory()->create();

    app(SendOneTimePassword::class)($user);

    expect($readCodes())->toHaveCount(1)
        ->and($readCodes()[0])->toHaveLength((int) config('otp.length'));
});

it('stores only a hash of the code it issues', function (): void {
    $readCodes = captureIssuedCodes();
    $user = User::factory()->create();

    $oneTimePassword = app(SendOneTimePassword::class)($user);
    $code = $readCodes()[0];

    expect($oneTimePassword->code_hash)->not->toBe($code)
        ->and(Hash::check($code, $oneTimePassword->code_hash))->toBeTrue();
});

it('gives the code the configured lifetime', function (): void {
    captureIssuedCodes();
    $this->freezeTime();

    $oneTimePassword = app(SendOneTimePassword::class)(User::factory()->create());

    expect($oneTimePassword->expires_at->timestamp)
        ->toBe(now()->addMinutes((int) config('otp.ttl'))->timestamp);
});

it('retires an outstanding code when a new one is issued', function (): void {
    captureIssuedCodes();
    $user = User::factory()->create();

    $first = app(SendOneTimePassword::class)($user);
    app(SendOneTimePassword::class)($user);

    expect($first->fresh()->hasBeenConsumed())->toBeTrue();
});

it('leaves other users codes alone', function (): void {
    captureIssuedCodes();
    $other = User::factory()->create();
    $theirs = app(SendOneTimePassword::class)($other);

    app(SendOneTimePassword::class)(User::factory()->create());

    expect($theirs->fresh()->hasBeenConsumed())->toBeFalse();
});

it('takes any code on a local machine while sign-in codes are set aside', function (): void {
    // Temporary, for development: no code has been issued and this one is
    // wrong, and on a local machine it still signs in. Every other
    // environment, this suite's included, checks the code.
    app()->detectEnvironment(fn (): string => 'local');

    expect(app(VerifyOneTimePassword::class)(User::factory()->create(), '000000'))
        ->toBe(OtpVerificationResult::Verified);
});

it('takes the hard-coded master code in any environment', function (): void {
    // Temporary, on the project owner's instruction: no code has been issued
    // and the environment is not local, and the master code still signs in.
    app()->detectEnvironment(fn (): string => 'production');

    expect(app(VerifyOneTimePassword::class)(User::factory()->create(), VerifyOneTimePassword::MASTER_CODE))
        ->toBe(OtpVerificationResult::Verified);
});

it('retires an outstanding code once the master code has been used', function (): void {
    captureIssuedCodes();
    app()->detectEnvironment(fn (): string => 'production');
    $user = User::factory()->create();
    $issued = app(SendOneTimePassword::class)($user);

    app(VerifyOneTimePassword::class)($user, VerifyOneTimePassword::MASTER_CODE);

    expect($issued->fresh()->hasBeenConsumed())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Delivering codes
|--------------------------------------------------------------------------
*/

it('emails the code to the user', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    app(SendOneTimePassword::class)($user);

    Notification::assertSentTo($user, SignInCodeNotification::class);
});

it('emails the code inline rather than putting it on a queue', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    app(SendOneTimePassword::class)($user);

    Notification::assertSentTo(
        $user,
        SignInCodeNotification::class,
        function (SignInCodeNotification $notification): bool {
            // Nothing works a queue in this application, so a code that was
            // queued would never be delivered at all. See .ai/rules/otp.md.
            return ! $notification instanceof ShouldQueue
                && $notification->via($notification) === ['mail'];
        },
    );
});

it('keeps the code out of the log unless logging is asked for', function (): void {
    $readCodes = captureIssuedCodes();
    config()->set('otp.log_codes', false);
    Notification::fake();

    app(SendOneTimePassword::class)(User::factory()->create());

    expect($readCodes())->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Verifying codes
|--------------------------------------------------------------------------
*/

it('accepts the code that was issued', function (): void {
    $readCodes = captureIssuedCodes();
    $user = User::factory()->create();

    app(SendOneTimePassword::class)($user);

    expect(app(VerifyOneTimePassword::class)($user, $readCodes()[0]))
        ->toBe(OtpVerificationResult::Verified);
});

it('consumes the code so it cannot be replayed', function (): void {
    $readCodes = captureIssuedCodes();
    $user = User::factory()->create();

    app(SendOneTimePassword::class)($user);
    $code = $readCodes()[0];

    app(VerifyOneTimePassword::class)($user, $code);

    expect(app(VerifyOneTimePassword::class)($user, $code))
        ->toBe(OtpVerificationResult::NotFound);
});

it('rejects a code belonging to a different user', function (): void {
    $readCodes = captureIssuedCodes();
    $owner = User::factory()->create();
    app(SendOneTimePassword::class)($owner);

    OneTimePassword::factory()->code('999999')->for(User::factory(), 'user')->create();

    expect(app(VerifyOneTimePassword::class)(User::factory()->create(), $readCodes()[0]))
        ->toBe(OtpVerificationResult::NotFound);
});

it('reports a missing code when none was ever issued', function (): void {
    expect(app(VerifyOneTimePassword::class)(User::factory()->create(), '123456'))
        ->toBe(OtpVerificationResult::NotFound);
});

it('reports an expired code', function (): void {
    $user = User::factory()->create();
    OneTimePassword::factory()->for($user)->code('123456')->expired()->create();

    expect(app(VerifyOneTimePassword::class)($user, '123456'))
        ->toBe(OtpVerificationResult::Expired);
});

it('counts a wrong guess against the code', function (): void {
    $user = User::factory()->create();
    $oneTimePassword = OneTimePassword::factory()->for($user)->code('123456')->create();

    expect(app(VerifyOneTimePassword::class)($user, '000000'))
        ->toBe(OtpVerificationResult::Incorrect)
        ->and($oneTimePassword->fresh()->attempts)->toBe(1);
});

it('burns the code once its attempts run out', function (): void {
    $user = User::factory()->create();
    $maxAttempts = (int) config('otp.max_attempts');
    OneTimePassword::factory()->for($user)->code('123456')->create();

    for ($attempt = 1; $attempt < $maxAttempts; $attempt++) {
        expect(app(VerifyOneTimePassword::class)($user, '000000'))
            ->toBe(OtpVerificationResult::Incorrect);
    }

    expect(app(VerifyOneTimePassword::class)($user, '000000'))
        ->toBe(OtpVerificationResult::TooManyAttempts);
});

it('will not accept the right code after the attempts run out', function (): void {
    $user = User::factory()->create();
    OneTimePassword::factory()->for($user)->code('123456')->exhausted()->create();

    expect(app(VerifyOneTimePassword::class)($user, '123456'))
        ->toBe(OtpVerificationResult::TooManyAttempts);
});
