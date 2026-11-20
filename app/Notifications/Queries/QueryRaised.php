<?php

namespace App\Notifications\Queries;

final class QueryRaised extends QueryNotification
{
    protected function staffSubject(): string
    {
        return 'Query Raised on '.$this->reference();
    }

    protected function staffLine(): string
    {
        return 'A query on this case is waiting for you.';
    }
}
