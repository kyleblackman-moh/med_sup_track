<?php

namespace App\Notifications\Cases;

/** NOTIF-1: a request was submitted (to intake and the CMO). */
final class CaseSubmitted extends CaseNotification
{
    protected function staffSubject(): string
    {
        return 'Request '.$this->reference().' Submitted';
    }

    protected function staffLine(): string
    {
        return 'A request has been submitted and is waiting for intake.';
    }
}
