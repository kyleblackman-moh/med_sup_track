<?php

namespace App\Notifications\Cases;

/** NOTIF-1, NOTIF-2: non-objection granted; the CMO issues the letter. Action. */
final class NonObjectionGranted extends StaffCaseNotification
{
    protected string $kind = self::ACTION;

    public function event(): string
    {
        return 'nonobjection_granted';
    }

    protected function staffSubject(): string
    {
        return 'Issue Letter for '.$this->reference();
    }

    protected function staffLine(): string
    {
        return 'Non-objection has been granted. The letter can now be issued.';
    }
}
