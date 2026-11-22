<?php

namespace App\Notifications\Vendor;

use App\Models\ProcurementCase;
use App\Notifications\CaseNotification;
use App\Notifications\Channels\CaseDatabaseChannel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * NOTIF-2: the ONLY notifications a vendor user receives about a case (four
 * classes). Always the bell and an email. The constructor takes the case id
 * and reference and nothing else, so no query text, message, reason,
 * document name or internal wording can reach one: each class is a single
 * fixed sentence. Security emails (password, activation) are separate.
 */
abstract class VendorNotification extends CaseNotification
{
    final public function __construct(string $caseId, ?string $reference)
    {
        parent::__construct($caseId, $reference);
    }

    public static function for(ProcurementCase $case): static
    {
        return new static((string) $case->getKey(), $case->reference_no);
    }

    /** Always action: the bell and an email. */
    protected string $kind = self::ACTION;

    /** @return list<string> */
    final public function via(object $notifiable): array
    {
        return [CaseDatabaseChannel::class, 'mail'];
    }

    /** Title Case subject. */
    abstract protected function subject(): string;

    /** The one fixed sentence; also the bell title. */
    abstract protected function sentence(): string;

    final public function title(): string
    {
        return $this->sentence();
    }

    final public function url(): string
    {
        return route('requests.show', $this->caseId);
    }

    final public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line($this->sentence())
            ->action('View Your Request', $this->url());
    }
}
