<?php

namespace App\Notifications;

use App\Enums\FilamentPanel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone that the product team have opened an account for them.
 *
 * No code travels in this message. Accounts carry no password either, so the
 * only thing to say is where to sign in; the code that lets them in is issued
 * by the sign-in page when they ask for it.
 *
 * It is sent inline alongside the sign-in codes: nothing in this application
 * uses a queue at the moment.
 */
class AccountCreatedNotification extends Notification
{
    public function __construct(
        private readonly string $signInUrl,
        private readonly ?string $tenantName = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // An account with no tenant is the product team's, so it is opened at
        // the platform itself.
        $where = $this->tenantName ?? FilamentPanel::Platform->brandName();

        return (new MailMessage)
            ->subject('Your account is ready')
            ->greeting('Your account is ready')
            ->line("An account has been created for you at {$where}.")
            ->line('There is no password to set. Enter your email address on the sign-in page and we will email you a code.')
            ->action('Sign in', $this->signInUrl)
            ->line('If you were not expecting this, you can ignore this email.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tenant_name' => $this->tenantName,
            'sign_in_url' => $this->signInUrl,
        ];
    }
}
