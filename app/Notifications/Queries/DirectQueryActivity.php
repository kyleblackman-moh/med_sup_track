<?php

namespace App\Notifications\Queries;

/** FR-19: a direct Technocrat and vendor query was opened or answered; the CMO's desk reads it (watchlist). Info. */
final class DirectQueryActivity extends QueryNotification
{
    protected string $kind = self::INFO;

    public function event(): string
    {
        return 'query_direct_activity';
    }

    protected function staffSubject(): string
    {
        return 'Direct Query Activity on '.$this->reference();
    }

    protected function staffLine(): string
    {
        return 'A direct query between the Technocrats and the vendor has a new message.';
    }
}
