<?php

namespace App\Notifications;

use App\Notifications\Channels\CaseDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NOTIF-2: every notification about a case. A notification is a pointer, not
 * the work: the "Needs my action" queue stays derived from case state and
 * assignments, never from these rows.
 *
 * - kind 'action': the bell (database) and a queued email.
 * - kind 'info': the bell only.
 *
 * The bell row holds ONLY case_id, reference, event, kind, title and url:
 * never message bodies, query text, reasons or document names. Opening the url
 * re-runs the normal policies. Queued, sent after the transaction commits (a
 * rolled-back action sends nothing), 3 tries with backoff, failures logged.
 *
 * Built from plain values taken when the event happened, never the model, so
 * a queue worker needs no access to the case.
 */
abstract class CaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const ACTION = 'action';

    public const INFO = 'info';

    public int $tries = 3;

    /** @var list<int> seconds before tries 2 and 3 */
    public array $backoff = [60, 300];

    /** ACTION or INFO; a subclass overrides it. */
    protected string $kind = self::ACTION;

    // Not readonly: the queue restores these on a subclass, which PHP forbids for readonly.
    public function __construct(
        public string $caseId,
        public ?string $reference,
    ) {
        $this->afterCommit();
    }

    /** A fixed event code (snake_case), stored in the bell row. */
    abstract public function event(): string;

    /** The bell line: fixed wording plus the reference, never user-typed text. */
    abstract public function title(): string;

    /** Where clicking goes: a page that authorises itself. */
    abstract public function url(): string;

    abstract public function toMail(object $notifiable): MailMessage;

    public function kind(): string
    {
        return $this->kind;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->kind === self::ACTION ? [CaseDatabaseChannel::class, 'mail'] : [CaseDatabaseChannel::class];
    }

    /**
     * The bell row's data. CaseDatabaseChannel writes case_id to its own column too.
     *
     * @return array{case_id: string, reference: string|null, event: string, kind: string, title: string, url: string}
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'case_id' => $this->caseId,
            'reference' => $this->reference,
            'event' => $this->event(),
            'kind' => $this->kind,
            'title' => $this->title(),
            'url' => $this->url(),
        ];
    }

    /** A job that gave up is logged with the case and the class only (no recipient address). */
    public function failed(Throwable $exception): void
    {
        Log::error('Notification failed', ['notification' => static::class, 'case_id' => $this->caseId, 'exception' => $exception::class]);
    }

    protected function reference(): string
    {
        return $this->reference ?? 'without a reference';
    }
}
