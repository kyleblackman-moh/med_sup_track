<?php

namespace App\Notifications\Cases;

/** NOTIF-1, NOTIF-2: a case came back to the CMO; detail names the office that returned it. Action. */
final class ReturnedToCmo extends StaffCaseNotification
{
    protected string $kind = self::ACTION;

    public function event(): string
    {
        return 'returned_to_cmo';
    }

    protected function staffSubject(): string
    {
        return 'Case '.$this->reference().' Returned to the CMO'.($this->detail !== null ? ' by '.$this->detail : '');
    }

    protected function staffLine(): string
    {
        return 'A case has been returned to the CMO'.($this->detail !== null ? ' by '.$this->detail : '').'.';
    }
}
