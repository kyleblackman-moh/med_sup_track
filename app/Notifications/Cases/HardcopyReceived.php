<?php

namespace App\Notifications\Cases;

/** NOTIF-2: the hardcopy was recorded as received (to the CMO). Info. */
final class HardcopyReceived extends StaffCaseNotification
{
    protected string $kind = self::INFO;

    public function event(): string
    {
        return 'hardcopy_received';
    }

    protected function staffSubject(): string
    {
        return 'Hardcopy Received for '.$this->reference();
    }

    protected function staffLine(): string
    {
        return 'The hardcopy for this case has been recorded as received.';
    }
}
