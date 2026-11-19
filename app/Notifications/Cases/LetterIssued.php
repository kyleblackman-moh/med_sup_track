<?php

namespace App\Notifications\Cases;

/** NOTIF-1: the non-objection letter was issued (to the vendor applicant). */
final class LetterIssued extends CaseNotification
{
    protected function staffSubject(): string
    {
        return 'Letter Issued for '.$this->reference();
    }

    protected function staffLine(): string
    {
        return 'The non-objection letter has been issued.';
    }
}
