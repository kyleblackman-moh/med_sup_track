<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADMIN-1: the email an Admin's new account (or a resend) gets. It carries a
 * password-reset token from the standard broker, so the link opens the
 * existing Reset Password page (route password.reset), where the person
 * chooses their own password. No password is ever shown or emailed.
 */
class AccountActivation extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Set Your Password for '.config('app.name'))
            ->line('An account has been set up for you. Choose your password to sign in.')
            ->action('Set Your Password', $url)
            ->line("This link expires in {$minutes} minutes. If it has expired, ask your administrator to send a new one.");
    }
}
