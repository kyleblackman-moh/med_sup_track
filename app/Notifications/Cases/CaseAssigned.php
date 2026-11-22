<?php

namespace App\Notifications\Cases;

/** NOTIF-1, NOTIF-2: a named officer was assigned to a review desk; detail is the desk label (Technical review, Funding confirmation). Action. */
final class CaseAssigned extends StaffCaseNotification
{
    protected string $kind = self::ACTION;

    public function event(): string
    {
        return 'case_assigned';
    }

    protected function staffSubject(): string
    {
        return ($this->detail !== null ? $this->detail.' Requested: ' : 'Case Assigned to You: ').$this->reference();
    }

    protected function staffLine(): string
    {
        return $this->detail !== null ? 'You have been assigned to '.mb_strtolower($this->detail).' on this case.' : 'A case has been assigned to you.';
    }
}
