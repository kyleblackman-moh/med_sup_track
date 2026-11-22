<?php

namespace App\Notifications\Cases;

/** NOTIF-2: a Technocrat recorded a technical outcome (to the CMO); detail is the outcome label. Info. */
final class TechnicalReviewRecorded extends StaffCaseNotification
{
    protected string $kind = self::INFO;

    public function event(): string
    {
        return 'technical_review_recorded';
    }

    protected function staffSubject(): string
    {
        return 'Technical Review Recorded on '.$this->reference().($this->detail !== null ? ': '.$this->detail : '');
    }

    protected function staffLine(): string
    {
        return 'A technical review outcome has been recorded'.($this->detail !== null ? ': '.$this->detail : '').'.';
    }
}
