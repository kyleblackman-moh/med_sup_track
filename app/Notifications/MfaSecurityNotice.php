<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * MFA-1: security emails about the user's own multi-factor authentication,
 * to that user only (as PasswordChanged). Separate from case notifications:
 * email only, never the bell. No codes, secrets or admin names.
 */
final class MfaSecurityNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const ENROLLED = 'enrolled';

    public const RECOVERY_USED = 'recovery_used';

    public const RESET_BY_ADMIN = 'reset_by_admin';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $userId, public readonly string $kind)
    {
        if (! in_array($kind, [self::ENROLLED, self::RECOVERY_USED, self::RESET_BY_ADMIN], true)) {
            throw new InvalidArgumentException("Unknown MFA notice {$kind}.");
        }

        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $line] = match ($this->kind) {
            self::ENROLLED => ['Multi-Factor Authentication Set Up', 'An authenticator app was set up for your account.'],
            self::RECOVERY_USED => ['A Recovery Code Was Used', 'A recovery code was used to sign in to your account. It cannot be used again.'],
            self::RESET_BY_ADMIN => ['Multi-Factor Authentication Reset', 'An administrator reset multi-factor authentication for your account. You will set up an authenticator app again the next time you sign in.'],
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->line('If you did not expect this, contact your administrator now.');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Notification failed', ['notification' => self::class, 'kind' => $this->kind, 'user_id' => $this->userId, 'exception' => $exception::class]);
    }
}
