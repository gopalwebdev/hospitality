<?php

namespace App\Filament\Auth;

use App\Actions\OneTimePasswords\SendOneTimePassword;
use App\Actions\OneTimePasswords\ThrottleOneTimePasswordRequests;
use App\Actions\OneTimePasswords\VerifyOneTimePassword;
use App\Enums\FilamentPanel;
use App\Enums\OtpRequestOutcome;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Passwordless sign-in, shared by every panel.
 *
 * The page runs in two steps inside one Livewire component: an address is
 * entered, a one-time code is issued for it, and the same page then asks for
 * that code. Whether an account exists for the address is never disclosed —
 * step one always reports the same thing, and every failure in step two reads
 * as a bad code.
 *
 * Each panel registers its own subclass so that its pages stay in its own
 * namespace and it can speak for itself on the way in.
 */
abstract class OtpLogin extends BaseLogin
{
    /**
     * Whether the page has moved on to asking for the code.
     */
    #[Locked]
    public bool $hasRequestedCode = false;

    /**
     * When the last code was sent, as a Unix timestamp.
     */
    #[Locked]
    public ?int $codeSentAt = null;

    /**
     * The panel this page signs people into.
     */
    abstract protected function panel(): FilamentPanel;

    /**
     * Issue a code for the address that was entered and move to step two.
     */
    public function requestCode(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        // Only the address is validated here. Taking the whole form's state
        // would trip over the empty code field when someone on step two asks
        // for a replacement code.
        $this->validateOnly('data.email', [
            'data.email' => ['required', 'string', 'email'],
        ], attributes: ['data.email' => 'email address']);

        $email = (string) ($this->data['email'] ?? '');

        // Nothing is sent to an address that cannot sign in here, and the page
        // says so rather than pretending a code is on its way.
        $user = User::query()->withEmail($email)->first();

        if (! ($user instanceof User) || ! $this->isUserAllowedToAccessPanel($user)) {
            throw ValidationException::withMessages([
                'data.email' => 'There is no account for that email address.',
            ]);
        }

        $throttle = app(ThrottleOneTimePasswordRequests::class);
        $outcome = $throttle($email);

        if ($outcome === OtpRequestOutcome::Cooldown && $this->resumeOutstandingCode($user)) {
            return;
        }

        if (! $outcome->mayIssueCode()) {
            Notification::make()
                ->title('Hold on')
                ->body($outcome->message(
                    $outcome === OtpRequestOutcome::Cooldown ? $throttle->secondsUntilNextRequest($email) : null,
                ))
                ->warning()
                ->send();

            return;
        }

        app(SendOneTimePassword::class)($user);

        $this->hasRequestedCode = true;
        $this->codeSentAt = now()->getTimestamp();

        Notification::make()
            ->title('Check your email')
            ->body($outcome->message())
            ->success()
            ->send();
    }

    /**
     * Return to the code step for a code that is still usable.
     *
     * Asking again inside the cooldown would otherwise leave someone who
     * already holds a good code stuck on step one until the wait runs out.
     */
    protected function resumeOutstandingCode(User $user): bool
    {
        $outstanding = $user->oneTimePasswords()
            ->live()
            ->latest('id')
            ->first(['id', 'attempts', 'created_at']);

        if ($outstanding === null || $outstanding->hasExhaustedAttempts()) {
            return false;
        }

        $this->hasRequestedCode = true;
        $this->codeSentAt = ($outstanding->created_at ?? now())->getTimestamp();

        Notification::make()
            ->title('Check your email')
            ->body('We already sent you a code. Enter it below.')
            ->info()
            ->send();

        return true;
    }

    /**
     * Go back to step one so a different address can be entered.
     */
    public function startOver(): void
    {
        $this->hasRequestedCode = false;

        $this->form->fill(['email' => $this->data['email'] ?? null]);
    }

    /**
     * Check the submitted code and sign the user in.
     */
    public function authenticate(): ?LoginResponse
    {
        if (! $this->hasRequestedCode) {
            $this->requestCode();

            return null;
        }

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        $user = User::query()->withEmail((string) $data['email'])->first();

        if (! ($user instanceof User) || ! $this->isUserAllowedToAccessPanel($user)) {
            $this->throwFailureValidationException();
        }

        $result = app(VerifyOneTimePassword::class)($user, $data['code']);

        if (! $result->isVerified()) {
            throw ValidationException::withMessages([
                'data.code' => $result->message(),
            ]);
        }

        // Receiving the code proves the address belongs to them, which is the
        // same thing a verification email would have established.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Filament::auth()->login($user);

        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getEmailFormComponent(),
                $this->getCodeFormComponent(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
                $this->getFormContentComponent(),
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            // Both of these are closures on purpose. The content schema is built
            // once per request, before the action that moves the page to step
            // two has run, so anything resolved eagerly here describes the step
            // the visitor has just left.
            ->livewireSubmitHandler(fn (): string => $this->hasRequestedCode ? 'authenticate' : 'requestCode')
            ->footer([
                // Two groups rather than one row: the button that submits the
                // form runs the full width, and the ways out sit under it as
                // links. In one row the third label wrapped onto two lines.
                // The key changes with the button's state so Livewire swaps the
                // row in fresh. Patched in place, the button kept the
                // aria-disabled="true" it was first drawn with, and Filament
                // swallows every click on a button marked that way.
                Actions::make($this->getPrimaryFormActions())
                    ->fullWidth()
                    ->key(fn (): string => 'form-actions-'
                        .($this->hasRequestedCode ? 'code-' : 'email-')
                        .($this->hasCompleteCode() ? 'ready' : 'waiting')),
                $this->getResendCountdownComponent(),
                Actions::make($this->getSecondaryFormActions())
                    ->alignment(Alignment::Center)
                    ->key('form-secondary-actions'),
            ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Sign in';
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->hasRequestedCode ? 'Enter your code' : 'Sign in';
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->hasRequestedCode) {
            return $this->panel()->signInDescription().' We will email you a code; there is no password.';
        }

        return sprintf('We emailed you a code. It expires in %d minutes.', (int) config('otp.ttl'));
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email address')
            ->email()
            ->required()
            ->prefixIcon(Heroicon::OutlinedEnvelope)
            ->placeholder('you@example.com')
            ->autocomplete('username')
            ->autofocus(fn (): bool => ! $this->hasRequestedCode)
            // Disabled once a code is out, so the address the code went to is
            // the one signed in with. Still dehydrated: authenticate() reads it.
            ->disabled(fn (): bool => $this->hasRequestedCode)
            ->dehydrated();
    }

    /**
     * How long until another code may be asked for, in seconds.
     *
     * Matches the cooldown ThrottleOneTimePasswordRequests enforces, so the
     * link appears exactly when a request through it would be accepted.
     */
    protected function secondsUntilResend(): int
    {
        if ($this->codeSentAt === null) {
            return 0;
        }

        return max(0, $this->codeSentAt + (int) config('otp.resend_cooldown') - now()->getTimestamp());
    }

    /**
     * A countdown to the resend link, ticked in the browser.
     *
     * At zero it refreshes the component once, which draws the link.
     */
    protected function getResendCountdownComponent(): Component
    {
        return Html::make(fn (): HtmlString => new HtmlString(sprintf(
            <<<'HTML'
                <p
                    x-data="{ secondsLeft: %d }"
                    x-init="const timer = setInterval(() => { if (--secondsLeft <= 0) { clearInterval(timer); $wire.$refresh() } }, 1000)"
                    style="text-align: center; font-size: 0.875rem; color: var(--gray-500);"
                >
                    You can ask for a new code in
                    <span x-text="Math.floor(secondsLeft / 60) + ':' + String(secondsLeft %% 60).padStart(2, '0')" style="font-variant-numeric: tabular-nums;"></span>
                </p>
                HTML,
            $this->secondsUntilResend(),
        )))
            ->key(fn (): string => 'resend-countdown-'.$this->codeSentAt)
            ->visible(fn (): bool => $this->hasRequestedCode && $this->secondsUntilResend() > 0);
    }

    protected function getCodeFormComponent(): Component
    {
        $length = $this->codeLength();

        return TextInput::make('code')
            ->label(sprintf('%d-digit code', $length))
            ->required()
            ->rule('digits:'.$length)
            ->autocomplete('one-time-code')
            ->autofocus(fn (): bool => $this->hasRequestedCode)
            ->live(debounce: 200)
            ->prefixIcon(Heroicon::OutlinedKey)
            ->extraInputAttributes([
                'inputmode' => 'numeric',
                'maxlength' => $length,
                'placeholder' => str_repeat('0', $length),
                // Digits set apart and centred, so a code reads as a code
                // rather than as a number typed into a box.
                'style' => 'text-align: center; letter-spacing: 0.45em; '
                    .'font-size: 1.125rem; font-weight: 600; text-indent: 0.45em;',
            ])
            ->visible(fn (): bool => $this->hasRequestedCode);
    }

    /**
     * How many digits a code carries.
     */
    protected function codeLength(): int
    {
        return (int) config('otp.length');
    }

    /**
     * Whether every digit of the code has been typed.
     *
     * The sign-in button stays disabled until this is true, so the form is
     * never submitted half-filled. The code field is live, so each keystroke
     * asks this again.
     */
    protected function hasCompleteCode(): bool
    {
        return mb_strlen((string) ($this->data['code'] ?? '')) === $this->codeLength();
    }

    /**
     * Every action the form can show, in the order it shows them.
     *
     * The list is fixed and each action decides whether it belongs on the step
     * being rendered. Returning a different list per step would not work: this
     * runs while the schema is built, which is before the action that changed
     * the step has run.
     *
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            ...$this->getPrimaryFormActions(),
            ...$this->getSecondaryFormActions(),
        ];
    }

    /**
     * The one button that submits the form, whichever step it is on.
     *
     * @return array<Action|ActionGroup>
     */
    protected function getPrimaryFormActions(): array
    {
        return [
            $this->getRequestCodeFormAction(),
            $this->getAuthenticateFormAction(),
        ];
    }

    /**
     * The ways out of step two, shown beneath the button as links.
     *
     * @return array<Action|ActionGroup>
     */
    protected function getSecondaryFormActions(): array
    {
        return [
            $this->getResendCodeFormAction(),
            $this->getStartOverFormAction(),
        ];
    }

    protected function getRequestCodeFormAction(): Action
    {
        return Action::make('requestCode')
            ->label('Email me a code')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->submit('requestCode')
            ->visible(fn (): bool => ! $this->hasRequestedCode);
    }

    protected function getAuthenticateFormAction(): Action
    {
        return Action::make('authenticate')
            ->label('Sign in')
            ->icon(Heroicon::OutlinedArrowRightOnRectangle)
            ->submit('authenticate')
            ->visible(fn (): bool => $this->hasRequestedCode)
            ->disabled(fn (): bool => ! $this->hasCompleteCode());
    }

    protected function getResendCodeFormAction(): Action
    {
        return Action::make('resendCode')
            ->label('Send a new code')
            ->icon(Heroicon::OutlinedArrowPath)
            ->link()
            ->action('requestCode')
            ->visible(fn (): bool => $this->hasRequestedCode && $this->secondsUntilResend() === 0);
    }

    protected function getStartOverFormAction(): Action
    {
        return Action::make('startOver')
            ->label('Use a different email')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->link()
            ->action('startOver')
            ->visible(fn (): bool => $this->hasRequestedCode);
    }

    /**
     * The panel never distinguishes a wrong code from an unusable account.
     */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.code' => 'That code is not correct.',
        ]);
    }
}
