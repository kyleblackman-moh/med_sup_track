<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * IT-ADMIN: the email a new account (or a resend) gets: a single-use, signed
 * activation link (AccountActivations) that opens the Activate Account page,
 * where the person chooses their own password. No password is ever shown or
 * emailed. Sent synchronously so the admin is told at once if it failed.
 */
class AccountActivation extends Notification
{
    use Queueable;

    public function __construct(public readonly string $url, public readonly int $hours) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Activate Your Account for '.config('app.name'))
            ->line('An account has been set up for you. Choose your password to activate it. You will then set up multi-factor authentication when you first sign in.')
            ->action('Activate Your Account', $this->url)
            ->line("This link works once and expires in {$this->hours} hours. If it has expired, ask your administrator to send a new one.");
    }
}
