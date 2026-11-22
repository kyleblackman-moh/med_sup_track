<?php

namespace App\Notifications\Cases;

/** NOTIF-1: a request was submitted (to intake and the CMO; never the PS office). Action. */
final class CaseSubmitted extends StaffCaseNotification
{
    protected string $kind = self::ACTION;

    public function event(): string
    {
        return 'case_submitted';
    }

    protected function staffSubject(): string
    {
        return 'Request '.$this->reference().' Submitted';
    }

    protected function staffLine(): string
    {
        return 'A request has been submitted and is waiting for intake.';
    }
}
