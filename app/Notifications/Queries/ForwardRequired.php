<?php

namespace App\Notifications\Queries;

final class ForwardRequired extends QueryNotification
{
    protected function staffSubject(): string
    {
        return 'Query to Forward on '.$this->reference();
    }

    protected function staffLine(): string
    {
        return "A query message is waiting for the CMO's Office to forward it.";
    }
}
