<?php

namespace App\Notifications\Cases;

/** NOTIF-2: all three gate conditions are met (technical favourable, funding confirmed, hardcopy received). To the CMO. Action. */
final class ReadyToPrepareFile extends StaffCaseNotification
{
    protected string $kind = self::ACTION;

    public function event(): string
    {
        return 'gate_met';
    }

    protected function staffSubject(): string
    {
        return 'Ready to Prepare File: '.$this->reference();
    }

    protected function staffLine(): string
    {
        return 'Technical review is favourable, funding is confirmed and the hardcopy is received. The file can now be prepared.';
    }
}
