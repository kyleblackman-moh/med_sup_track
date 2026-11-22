<?php

namespace App\Notifications\Cases;

/** NOTIF-2: CAB recorded a funding outcome (to the CMO); detail is the outcome label. Info. */
final class FundingReviewRecorded extends StaffCaseNotification
{
    protected string $kind = self::INFO;

    public function event(): string
    {
        return 'funding_recorded';
    }

    protected function staffSubject(): string
    {
        return 'Funding Recorded on '.$this->reference().($this->detail !== null ? ': '.$this->detail : '');
    }

    protected function staffLine(): string
    {
        return 'A funding outcome has been recorded'.($this->detail !== null ? ': '.$this->detail : '').'.';
    }
}
