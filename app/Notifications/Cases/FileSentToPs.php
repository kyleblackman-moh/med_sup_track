<?php

namespace App\Notifications\Cases;

/** NOTIF-1, NOTIF-2: the file reached the PS (WITH_PS): an assignee, else every nonobjection.grant holder. Action. */
final class FileSentToPs extends StaffCaseNotification
{
    protected string $kind = self::ACTION;

    public function event(): string
    {
        return 'file_with_ps';
    }

    protected function staffSubject(): string
    {
        return 'File '.$this->reference().' Sent to the PS Office';
    }

    protected function staffLine(): string
    {
        return 'A file has been sent to the PS office for a decision.';
    }
}
