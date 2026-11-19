<?php

namespace App\Notifications\Queries;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OVERNIGHT-2 (D4): one queued email per query event, like CaseNotification
 * (mail only, after commit, 3 tries). Never the message text, the query
 * type, a role or a name.
 *
 * - Staff: the reference, one sentence and a link to the case's Queries tab.
 * - Vendor: the reference, ONE generic sentence and a link to their request.
 */
abstract class QueryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    final public function __construct(
        public string $caseId,
        public ?string $reference,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    abstract protected function staffSubject(): string;

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
            ->action('Open the Queries', route('cases.show', ['procurementCase' => $this->caseId, 'tab' => 'queries']));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Notification failed', ['notification' => static::class, 'case_id' => $this->caseId, 'exception' => $exception::class]);
    }

    protected function reference(): string
    {
        return $this->reference ?? 'without a reference';
    }
}
