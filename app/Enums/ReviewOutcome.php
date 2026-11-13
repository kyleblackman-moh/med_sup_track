<?php

namespace App\Enums;

/**
 * What one reviewer recorded on their track (slice 10b). Stored in
 * case_track_events.outcome; the case's track value is derived from these.
 */
enum ReviewOutcome: string
{
    case Favourable = 'favourable';
    case NotFavourable = 'not_favourable';
    case Confirmed = 'confirmed';
    case NotConfirmed = 'not_confirmed';

    public function desk(): ReviewDesk
    {
        return match ($this) {
            self::Favourable, self::NotFavourable => ReviewDesk::Technical,
            self::Confirmed, self::NotConfirmed => ReviewDesk::Funding,
        };
    }

    public function isPositive(): bool
    {
        return $this === self::Favourable || $this === self::Confirmed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Favourable => 'Favourable',
            self::NotFavourable => 'Not favourable',
            self::Confirmed => 'Funding confirmed',
            self::NotConfirmed => 'Funding not confirmed',
        };
    }
}
