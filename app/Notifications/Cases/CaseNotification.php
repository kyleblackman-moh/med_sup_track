<?php

namespace App\Notifications\Cases;

use App\Enums\CaseStatus;
use App\Models\ProcurementCase;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NOTIF-1: one queued email per case event (mail only, sent after the
 * transaction commits, 3 tries with backoff). Built from plain values taken
 * when the event happened, never the model, so a queue worker needs no access
 * to the case.
 *
 * Content (C3): staff get the reference, request type, status and a link to
 * the case. A vendor gets the reference, ONE generic sentence and a link to
 * their request: never internal messages, comments, funding detail, reviewer
 * names or the item (drug) name. One sender (config mail.from).
 */
abstract class CaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds before tries 2 and 3 */
    public array $backoff = [60, 300];

    // Not readonly: the queue restores these from a subclass (SerializesModels), which PHP forbids for readonly.
    final public function __construct(
        public string $caseId,
        public ?string $reference,
        public string $requestType,
        public string $status,
    ) {
        $this->afterCommit();
    }

    public static function for(ProcurementCase $case): static
    {
        $status = $case->status instanceof CaseStatus ? $case->status : CaseStatus::from((string) $case->status);

        return new static(
            (string) $case->getKey(),
            $case->reference_no,
            (string) $case->procurementType()->value('name'),
            $status->label(),
        );
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Title Case subject for staff. */
    abstract protected function staffSubject(): string;

    /** The one sentence saying what happened, for staff. */
    abstract protected function staffLine(): string;

    public function toMail(object $notifiable): MailMessage
    {
        if ($notifiable instanceof User && $notifiable->isVendor()) {
            return (new MailMessage)
                ->subject('Update on Your Request '.$this->reference())
                ->line('There is an update on your request '.$this->reference().'. Sign in to see it.')
                ->action('View Your Request', route('requests.show', $this->caseId));
        }

        return (new MailMessage)
            ->subject($this->staffSubject())
            ->line($this->staffLine())
            ->line('Reference: '.$this->reference())
            ->line('Request type: '.$this->requestType)
            ->line('Status: '.$this->status)
            ->action('Open the Case', route('cases.show', $this->caseId));
    }

    /** C4: a job that gave up is logged with the case and the class only (no recipient address). */
    public function failed(Throwable $exception): void
    {
        Log::error('Notification failed', ['notification' => static::class, 'case_id' => $this->caseId, 'exception' => $exception::class]);
    }

    protected function reference(): string
    {
        return $this->reference ?? 'without a reference';
    }
}
