<?php

namespace App\Notifications\Queries;

/** The answer to a via-CMO query arrived and is held: the CMO's desk forwards it to the raiser. Action. */
final class ForwardResponse extends QueryNotification
{
    protected string $kind = self::ACTION;

    public function event(): string
    {
        return 'query_forward_response';
    }

    protected function staffSubject(): string
    {
        return 'Forward Response on '.$this->reference();
    }

    protected function staffLine(): string
    {
        return "An answer to a query is waiting for the CMO's Office to forward it.";
    }
}
