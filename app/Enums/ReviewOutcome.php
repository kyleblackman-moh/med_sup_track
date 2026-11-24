<?php

namespace App\Enums;

/**
 * What one reviewer recorded on their track (slice 10b). Stored in
 * case_track_events.outcome; the case's track value is derived from these.
 *
 * Kyle, 2026-11-24: the CAB funding statuses are exactly three. The stored
 * values of the first and last are kept from slice 10b (case_track_events is
 * insert-only, so old rows are never rewritten; they read with the new labels):
 *   confirmed            Availability of Funds   funding confirmed
 *   verified_by_accounts Verified by Accounts    funding confirmed
 *   not_confirmed        Virement Required       not confirmed; reason required (internal)
 */
enum ReviewOutcome: string
{
    case Favourable = 'favourable';
    case NotFavourable = 'not_favourable';
    case Confirmed = 'confirmed';
    case VerifiedByAccounts = 'verified_by_accounts';
    case NotConfirmed = 'not_confirmed';

    public function desk(): ReviewDesk
    {
        return match ($this) {
            self::Favourable, self::NotFavourable => ReviewDesk::Technical,
            self::Confirmed, self::VerifiedByAccounts, self::NotConfirmed => ReviewDesk::Funding,
        };
    }

    /** Positive outcomes complete the track; a negative one needs a reason. */
    public function isPositive(): bool
    {
        return in_array($this, [self::Favourable, self::Confirmed, self::VerifiedByAccounts], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Favourable => 'Favourable',
            self::NotFavourable => 'Not favourable',
            self::Confirmed => 'Availability of Funds',
            self::VerifiedByAccounts => 'Verified by Accounts',
            self::NotConfirmed => 'Virement Required',
        };
    }
}
