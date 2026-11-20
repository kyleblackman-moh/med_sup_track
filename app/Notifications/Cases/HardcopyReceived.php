<?php

namespace App\Notifications\Cases;

/** NOTIF-1: the hardcopy was recorded as received (to the CMO and the vendor applicant). */
final class HardcopyReceived extends CaseNotification
{
    protected function staffSubject(): string
    {
        return 'Hardcopy Received for '.$this->reference();
    }

    protected function staffLine(): string
    {
        return 'The hardcopy for this case has been recorded as received.';
    }
}
