<?php

namespace App\Notifications\Cases;

/** NOTIF-2: Legal confirmed the contract; the file is now with the PS (to the CMO). Info. */
final class LegalConfirmed extends StaffCaseNotification
{
    protected string $kind = self::INFO;

    public function event(): string
    {
        return 'legal_confirmed';
    }

    protected function staffSubject(): string
    {
        return 'Legal Confirmed '.$this->reference();
    }

    protected function staffLine(): string
    {
        return 'Legal has confirmed the contract. The file is now with the PS office.';
    }
}
