<?php

namespace App\Notifications\Cases;

use App\Enums\CaseStatus;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Notifications\CaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use LogicException;

/**
 * NOTIF-1, NOTIF-2: a case event for staff. The bell row and the email carry
 * the reference, request type, status and a link to the case. $detail is a
 * fixed system word (an office name, an outcome label, a desk), never
 * user-typed text. Vendors are never sent one of these (VendorNotification).
 */
abstract class StaffCaseNotification extends CaseNotification
{
    final public function __construct(
        string $caseId,
        ?string $reference,
        public string $requestType,
        public string $status,
        public ?string $detail = null,
    ) {
        parent::__construct($caseId, $reference);
    }

    public static function for(ProcurementCase $case, ?string $detail = null): static
    {
        $status = $case->status instanceof CaseStatus ? $case->status : CaseStatus::from((string) $case->status);

        return new static(
            (string) $case->getKey(),
            $case->reference_no,
            (string) $case->procurementType()->value('name'),
            $status->label(),
            $detail,
        );
    }

    /** Title Case subject for the email. */
    abstract protected function staffSubject(): string;

    /** The one sentence saying what happened. */
    abstract protected function staffLine(): string;

    public function title(): string
    {
        return $this->staffSubject();
    }

    public function url(): string
    {
        return route('cases.show', $this->caseId);
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Recipients are staff by construction; refuse rather than mail a vendor internal wording.
        if ($notifiable instanceof User && $notifiable->isVendor()) {
            throw new LogicException('A staff notification was addressed to a vendor user.');
        }

        return (new MailMessage)
            ->subject($this->staffSubject())
            ->line($this->staffLine())
            ->line('Reference: '.$this->reference())
            ->line('Request type: '.$this->requestType)
            ->line('Status: '.$this->status)
            ->action('Open the Case', $this->url());
    }
}
