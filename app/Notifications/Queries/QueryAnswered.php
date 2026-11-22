<?php

namespace App\Notifications\Queries;

/** The answer to a query reached the staff member who raised it (direct, or forwarded by the CMO). Action. */
final class QueryAnswered extends QueryNotification
{
    protected string $kind = self::ACTION;

    public function event(): string
    {
        return 'query_answered';
    }

    protected function staffSubject(): string
    {
        return 'Query Answered on '.$this->reference();
    }

    protected function staffLine(): string
    {
        return 'A query you raised on this case has been answered.';
    }
}
