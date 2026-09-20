<?php

namespace App\Filament\Platform\Resources\Users\Pages;

use App\Actions\OneTimePasswords\SendOneTimePassword;
use App\Actions\OneTimePasswords\ThrottleOneTimePasswordRequests;
use App\Actions\OneTimePasswords\VerifyOneTimePassword;
use App\Actions\Users\CreateUserAccount;
use App\Filament\Platform\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use LogicException;

/**
 * Open an account, with the admin confirming by one-time code.
 *
 * The page runs in two steps inside one Livewire component, the way the sign-in
 * page does: the account details are filled in and a code is emailed to the
 * admin doing it, and the same page then asks for that code before
 * anything is written. Creating an account is what lets someone into a panel,
 * so it is held to the same proof as signing in — and a session left open on an
 * unattended laptop cannot mint one.
 *
 * The code goes to the admin, not to the new account: the new account has
 * no mailbox we have proved anything about yet. What it gets instead, once it
 * exists, is AccountCreatedNotification.
 */
class CreateUser extends CreateRecord
{
    #[\Override]
    protected static string $resource = UserResource::class;

    /**
     * Creating another would need a second code, so the page does not offer it.
     */
    #[\Override]
    protected static bool $canCreateAnother = false;

    /**
     * Whether the page has moved on to asking for the code.
     */
    #[Locked]
    public bool $hasRequestedCode = false;

    /**
     * Open with a tenant and a role already chosen, when arrived at from
     * one — CreateTenant sends a newly onboarded tenant straight here,
     * and so does the roster on a tenant's own record.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill($this->prefillFromQuery());

        $this->callHook('afterFill');
    }

    /**
     * The tenant and role that whoever linked here already chose.
     *
     * Null rather than an empty array when there is nothing to prefill:
     * Schema::fill() hydrates each field's own default only when it is handed
     * null, and skips them for any array, an empty one included.
     *
     * Only these two fields are read, and the form validates both: an unknown
     * tenant id or role name simply matches no option and is picked by
     * hand instead.
     *
     * @return array<string, mixed>|null
     */
    private function prefillFromQuery(): ?array
    {
        $role = request()->string('role')->toString();

        $prefill = array_filter([
            'tenant_id' => request()->integer('tenant_id') ?: null,
            'roles' => filled($role) ? [$role] : null,
        ]);

        return $prefill === [] ? null : $prefill;
    }

    /**
     * Step one: check the details, then email the admin a code.
     *
     * Nothing is sent for a form that could not be submitted anyway, so the
     * account fields are validated first.
     */
    public function requestCode(): void
    {
        $this->authorizeAccess();

        $this->validateAccountDetails();

        $confirmer = $this->confirmer();

        $outcome = app(ThrottleOneTimePasswordRequests::class)($confirmer->email);

        if (! $outcome->mayIssueCode()) {
            Notification::make()
                ->title('Hold on')
                ->body($outcome->message())
                ->warning()
                ->send();

            return;
        }

        app(SendOneTimePassword::class)($confirmer);

        $this->hasRequestedCode = true;

        Notification::make()
            ->title('Check your email')
            ->body(sprintf('We emailed a code to %s. Enter it to create this account.', $confirmer->email))
            ->success()
            ->send();
    }

    /**
     * Go back to step one so the details can be changed.
     */
    public function startOver(): void
    {
        $this->hasRequestedCode = false;

        // Only the code is dropped. Refilling the form would throw away the
        // account details, which are the very thing being gone back to.
        $data = $this->data ?? [];
        $data['confirmation_code'] = null;

        $this->data = $data;
    }

    /**
     * Submit handler for both steps.
     *
     * On step one this only issues a code; the real creation runs on step two,
     * where the parent's lifecycle checks the code in beforeCreate().
     */
    public function create(bool $another = false): void
    {
        if (! $this->hasRequestedCode) {
            $this->requestCode();

            return;
        }

        parent::create($another);
    }

    /**
     * The resource's own form, with the confirmation step appended.
     *
     * The code field belongs to this page rather than to UserResource: editing
     * an existing account asks for no code, so putting it on the shared schema
     * would leave the edit page carrying a field it never shows.
     */
    public function form(Schema $schema): Schema
    {
        $configured = static::getResource()::form($schema);

        return $configured->components([
            ...$configured->getComponents(withHidden: true),
            $this->getConfirmationCodeSection(),
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->hasRequestedCode) {
            return 'Creating an account is confirmed by one-time code, like signing in.';
        }

        return sprintf(
            'We emailed a code to %s. It expires in %d minutes.',
            $this->confirmer()->email,
            (int) config('otp.ttl'),
        );
    }

    /**
     * Check the code before anything is written.
     *
     * This runs inside the parent's transaction, so a wrong code rolls back and
     * surfaces on the code field rather than leaving a half-made account.
     */
    protected function beforeCreate(): void
    {
        $result = app(VerifyOneTimePassword::class)(
            $this->confirmer(),
            (string) ($this->data['confirmation_code'] ?? ''),
        );

        if ($result->isVerified()) {
            return;
        }

        throw ValidationException::withMessages([
            'data.confirmation_code' => $result->message(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateUserAccount::class)(
            name: (string) $data['name'],
            email: (string) $data['email'],
            tenantId: filled($data['tenant_id'] ?? null) ? (int) $data['tenant_id'] : null,
            isAdmin: (bool) ($data['is_admin'] ?? false),
            roleNames: array_values((array) ($data['roles'] ?? [])),
        );
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Account created, and they have been emailed about it';
    }

    /**
     * Every action the form can show, in the order it shows them.
     *
     * The list is fixed and each action decides whether it belongs on the step
     * being rendered: the schema is built before the action that moved the page
     * to step two has run, so a list chosen per step would describe the step
     * just left.
     *
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getRequestCodeFormAction(),
            $this->getConfirmAndCreateFormAction(),
            $this->getResendCodeFormAction(),
            $this->getStartOverFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getRequestCodeFormAction(): Action
    {
        return Action::make('requestCode')
            ->label('Email me a code')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->submit('create')
            ->visible(fn (): bool => ! $this->hasRequestedCode);
    }

    protected function getConfirmAndCreateFormAction(): Action
    {
        return Action::make('create')
            ->label('Confirm and create')
            ->icon(Heroicon::OutlinedUserPlus)
            ->submit('create')
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
            ->visible(fn (): bool => $this->hasRequestedCode);
    }

    protected function getStartOverFormAction(): Action
    {
        return Action::make('startOver')
            ->label('Change the details')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->link()
            ->action('startOver')
            ->visible(fn (): bool => $this->hasRequestedCode);
    }

    protected function getConfirmationCodeSection(): Section
    {
        return Section::make('Confirm it is you')
            ->description('The code we emailed you, which is what authorises this account being created.')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->schema([$this->getConfirmationCodeFormComponent()])
            ->visible(fn (): bool => $this->hasRequestedCode);
    }

    protected function getConfirmationCodeFormComponent(): TextInput
    {
        $length = (int) config('otp.length');

        return TextInput::make('confirmation_code')
            ->label(sprintf('%d-digit code', $length))
            ->required()
            ->rule('digits:'.$length)
            ->autocomplete('one-time-code')
            ->autofocus()
            ->live(debounce: 200)
            ->prefixIcon(Heroicon::OutlinedShieldCheck)
            ->helperText('From the email we just sent you. It confirms that you are the one creating this account.')
            ->extraInputAttributes([
                'inputmode' => 'numeric',
                'maxlength' => $length,
                'placeholder' => str_repeat('0', $length),
            ])
            ->dehydrated(false);
    }

    /**
     * Validate the account details, before a code is worth sending.
     *
     * On step one the code section is hidden, so this validates everything
     * except the code — which is the point: no email goes out for a form that
     * would be rejected anyway.
     */
    protected function validateAccountDetails(): void
    {
        $this->form->validate();
    }

    /**
     * Whether every digit of the code has been typed.
     */
    protected function hasCompleteCode(): bool
    {
        return mb_strlen((string) ($this->data['confirmation_code'] ?? '')) === (int) config('otp.length');
    }

    /**
     * The admin whose code confirms this creation.
     */
    protected function confirmer(): User
    {
        $user = Filament::auth()->user();

        throw_unless($user instanceof User, LogicException::class, 'Creating an account requires a signed-in admin.');

        return $user;
    }
}
