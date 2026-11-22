<?php

namespace App\Notifications\Queries;

use App\Models\User;
use App\Notifications\CaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use LogicException;

/**
 * OVERNIGHT-2 (D4), NOTIF-2: a query event for staff. Never the message text,
 * the query type, a role or a name: the reference, one sentence and a link to
 * the case's Queries tab. Vendors get VendorNotification classes instead.
 */
abstract class QueryNotification extends CaseNotification
{
    final public function __construct(string $caseId, ?string $reference)
    {
        parent::__construct($caseId, $reference);
    }

    abstract protected function staffSubject(): string;

    abstract protected function staffLine(): string;

    public function title(): string
    {
        return $this->staffSubject();
    }

    public function url(): string
    {
        return route('cases.show', [$this->caseId, 'tab' => 'queries']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($notifiable instanceof User && $notifiable->isVendor()) {
            throw new LogicException('A staff query notification was addressed to a vendor user.');
        }

        return (new MailMessage)
            ->subject($this->staffSubject())
            ->line($this->staffLine())
            ->line('Reference: '.$this->reference())
            ->action('Open the Queries', $this->url());
    }
}
