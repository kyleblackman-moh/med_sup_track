<?php

namespace App\Notifications;

use App\Enums\Role;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * IT-ADMIN: a security email about the user's own account, to that user only
 * (as MfaSecurityNotice). No admin names, no reason text. Sent after commit.
 */
final class AccountSecurityNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const ROLE_CHANGED = 'role_changed';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $userId, public readonly string $kind, public readonly ?string $role = null)
    {
        if ($kind !== self::ROLE_CHANGED) {
            throw new InvalidArgumentException("Unknown account notice {$kind}.");
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
        $label = Role::tryFrom((string) $this->role)?->label() ?? 'a new role';

        return (new MailMessage)
            ->subject('Your Role Was Changed')
            ->line("An administrator changed your role to {$label}. You have been signed out; sign in again to continue.")
            ->line('If you did not expect this, contact your administrator now.');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Notification failed', ['notification' => self::class, 'kind' => $this->kind, 'user_id' => $this->userId, 'exception' => $exception::class]);
    }
}
