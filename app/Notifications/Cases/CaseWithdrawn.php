<?php

namespace App\Notifications\Cases;

/** NOTIF-2: the vendor withdrew a submitted case (to the CMO). Info. */
final class CaseWithdrawn extends StaffCaseNotification
{
    protected string $kind = self::INFO;

    public function event(): string
    {
        return 'case_withdrawn';
    }

    protected function staffSubject(): string
    {
        return 'Request '.$this->reference().' Withdrawn';
    }

    protected function staffLine(): string
    {
        return 'The vendor has withdrawn this request.';
    }
}
