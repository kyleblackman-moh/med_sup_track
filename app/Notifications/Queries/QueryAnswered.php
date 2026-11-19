<?php

namespace App\Notifications\Queries;

final class QueryAnswered extends QueryNotification
{
    protected function staffSubject(): string
    {
        return 'Query Answered on '.$this->reference();
    }

    protected function staffLine(): string
    {
        return 'A query you raised on this case has an answer.';
    }
}
