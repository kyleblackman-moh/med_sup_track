<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NOTIF-1: an upload failed the virus scan and was quarantined (to the users
 * holding the admin permission). The case reference and document id only:
 * never the file name (the uploader typed it) or anything inside the case.
 */
final class InfectedUpload extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $documentId,
        public readonly ?string $caseId,
        public readonly ?string $reference,
    ) {
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
            ->subject('A File Was Quarantined')
            ->line('A file uploaded to request '.($this->reference ?? 'without a reference (draft)').' failed the virus scan and was quarantined.')
            ->line('Document: '.$this->documentId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Notification failed', ['notification' => self::class, 'case_id' => $this->caseId, 'document_id' => $this->documentId, 'exception' => $exception::class]);
    }
}
