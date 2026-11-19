<?php

namespace App\Notifications\Cases;

/** NOTIF-1: the file went to the PS office (to the PS office; not on submission). */
final class FileSentToPs extends CaseNotification
{
    protected function staffSubject(): string
    {
        return 'File '.$this->reference().' Sent to the PS Office';
    }

    protected function staffLine(): string
    {
        return 'A file has been sent to the PS office for a decision.';
    }
}
