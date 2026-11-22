<?php

namespace App\Support;

use App\Enums\AuditAction;
use Illuminate\Events\Dispatcher;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

/**
 * IT-ADMIN: email failures for the IT health page, as mail.send_failed audit
 * rows (best-effort; actor "system"). Class names only, never an address,
 * subject or body.
 *
 * - A queued notification or mailable that has failed for good (JobFailed).
 * - A channel that reported a failure (NotificationFailed).
 * - AccountActivations records its own synchronous send failures.
 */
final class OperationsEventAuditor
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            JobFailed::class => 'jobFailed',
            NotificationFailed::class => 'notificationFailed',
        ];
    }

    public function jobFailed(JobFailed $event): void
    {
        try {
            $command = $event->job->payload()['data']['commandName'] ?? null;
        } catch (Throwable) {
            return;
        }

        if (! in_array($command, [SendQueuedNotifications::class, SendQueuedMailable::class], true)) {
            return;
        }

        Audit::recordBestEffort(AuditAction::MailSendFailed, null, [
            'notification' => (string) $event->job->resolveName(),
            'exception' => $event->exception::class,
            'via' => 'queue',
        ], Audit::ACTOR_SYSTEM);
    }

    public function notificationFailed(NotificationFailed $event): void
    {
        $notifiable = $event->notifiable;

        Audit::recordBestEffort(AuditAction::MailSendFailed, is_object($notifiable) && method_exists($notifiable, 'getKey')
            ? ['type' => 'user', 'id' => $notifiable->getKey()]
            : null, [
                'notification' => $event->notification::class,
                'channel' => (string) $event->channel,
                'via' => 'channel',
            ], Audit::ACTOR_SYSTEM);
    }
}
