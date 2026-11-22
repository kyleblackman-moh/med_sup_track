<?php

namespace App\Notifications\Queries;

/** A query (Legal, PS or a return) arrived on a via-CMO route and is held: the CMO's desk forwards it. Action. */
final class ForwardRequired extends QueryNotification
{
    protected string $kind = self::ACTION;

    public function event(): string
    {
        return 'query_forward_required';
    }

    protected function staffSubject(): string
    {
        return 'Query to Forward on '.$this->reference();
    }

    protected function staffLine(): string
    {
        return "A query is waiting for the CMO's Office to forward it.";
    }
}
