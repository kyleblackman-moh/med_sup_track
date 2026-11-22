<?php

namespace App\Notifications\Cases;

/** NOTIF-2: the file reached Legal (WITH_LEGAL): the assigned officer, else every legal.confirm holder. Action. */
final class FileSentToLegal extends StaffCaseNotification
{
    protected string $kind = self::ACTION;

    public function event(): string
    {
        return 'file_with_legal';
    }

    protected function staffSubject(): string
    {
        return 'File '.$this->reference().' Sent to Legal';
    }

    protected function staffLine(): string
    {
        return 'A file has been sent to Legal to confirm the contract.';
    }
}
