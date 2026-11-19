<?php

namespace App\Notifications\Cases;

/** NOTIF-1: a case was assigned to a user (to the assignee). */
final class CaseAssigned extends CaseNotification
{
    protected function staffSubject(): string
    {
        return 'Case '.$this->reference().' Assigned to You';
    }

    protected function staffLine(): string
    {
        return 'A case has been assigned to you.';
    }
}
