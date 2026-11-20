<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/** NOTIF-1: the user's password was changed or reset (to that user). */
final class PasswordChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $userId)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Password Was Changed')
            ->line('The password for your account was changed.')
            ->line('If you did not do this, contact your administrator now.');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Notification failed', ['notification' => self::class, 'user_id' => $this->userId, 'exception' => $exception::class]);
    }
}
