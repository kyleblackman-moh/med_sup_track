<?php

namespace App\Notifications\Cases;

/** NOTIF-1: the PS granted non-objection (to the CMO). */
final class NonObjectionGranted extends CaseNotification
{
    protected function staffSubject(): string
    {
        return 'Non-Objection Granted for '.$this->reference();
    }

    protected function staffLine(): string
    {
        return 'Non-objection has been granted. The letter can now be issued.';
    }
}
