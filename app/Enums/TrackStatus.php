<?php

namespace App\Enums;

/**
 * Parallel gate tracks on a case (spec 6.4).
 *
 * technical_track uses PENDING / FAVOURABLE / NOT_FAVOURABLE / QUERY_OPEN;
 * funding_track uses PENDING / CONFIRMED / NOT_CONFIRMED / QUERY_OPEN.
 * A null track means the case has not been routed to review yet.
 *
 * NOT_FAVOURABLE and NOT_CONFIRMED were added in slice 10b (Option 1, agreed
 * with Kyle): spec 6.4 lists only pending, favourable/confirmed and query_open.
 * The T4 gate passes only on FAVOURABLE and CONFIRMED, so a negative value keeps
 * it closed. QUERY_OPEN stays reserved for the query overlay (spec 7.5).
 */
enum TrackStatus: string
{
    case PENDING = 'PENDING';
    case FAVOURABLE = 'FAVOURABLE';
    case NOT_FAVOURABLE = 'NOT_FAVOURABLE';
    case CONFIRMED = 'CONFIRMED';
    case NOT_CONFIRMED = 'NOT_CONFIRMED';
    case QUERY_OPEN = 'QUERY_OPEN';

    /** Plain display text for staff. No deadline or lateness meaning. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::FAVOURABLE => 'Favourable consideration given',
            self::NOT_FAVOURABLE => 'Not favourable',
            self::CONFIRMED => 'Funding confirmed',
            self::NOT_CONFIRMED => 'Funding not confirmed',
            self::QUERY_OPEN => 'Query open',
        };
    }
}
