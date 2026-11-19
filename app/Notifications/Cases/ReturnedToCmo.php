<?php

namespace App\Notifications\Cases;

/** NOTIF-1: a case came back to the CMO (to the CMO). */
final class ReturnedToCmo extends CaseNotification
{
    protected function staffSubject(): string
    {
        return 'Case '.$this->reference().' Returned to the CMO';
    }

    protected function staffLine(): string
    {
        return 'A case has been returned to the CMO.';
    }
}
