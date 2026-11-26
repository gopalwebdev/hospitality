<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a user the one-time code that signs them in.
 *
 * It is sent inline rather than queued, on the project owner's instruction:
 * nothing in this application uses a queue at the moment, and a code that
 * waits on a worker that is not running never arrives at all.
 */
class SignInCodeNotification extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly int $expiresInMinutes,
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
        return (new MailMessage)
            ->subject('Your sign-in code')
            ->greeting('Your sign-in code')
            ->line('Enter this code to finish signing in:')
            ->line("**{$this->code}**")
            ->line("It expires in {$this->expiresInMinutes} minutes and can only be used once.")
            ->line('If you did not ask to sign in, you can ignore this email.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'expires_in_minutes' => $this->expiresInMinutes,
        ];
    }
}
