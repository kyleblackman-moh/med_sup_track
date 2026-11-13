<?php

namespace App\Enums;

/**
 * Parallel gate tracks on a case (spec 6.4).
 *
 * technical_track uses PENDING / FAVOURABLE / QUERY_OPEN;
 * funding_track uses PENDING / CONFIRMED / QUERY_OPEN.
 * A null track means the case has not been routed to review yet.
 */
enum TrackStatus: string
{
    case PENDING = 'PENDING';
    case FAVOURABLE = 'FAVOURABLE';
    case CONFIRMED = 'CONFIRMED';
    case QUERY_OPEN = 'QUERY_OPEN';

    /** Plain display text for staff. No deadline or lateness meaning. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::FAVOURABLE => 'Favourable consideration given',
            self::CONFIRMED => 'Funding confirmed',
            self::QUERY_OPEN => 'Query open',
        };
    }
}
