<?php

namespace App\Notifications\Queries;

/** A query reached a staff desk (direct, or forwarded by the CMO): its holders who can see the case, plus the officer the CMO named. Action. */
final class QueryRaised extends QueryNotification
{
    protected string $kind = self::ACTION;

    public function event(): string
    {
        return 'query_received';
    }

    protected function staffSubject(): string
    {
        return 'Query for You on '.$this->reference();
    }

    protected function staffLine(): string
    {
        return 'A query on this case is waiting for your answer.';
    }
}
