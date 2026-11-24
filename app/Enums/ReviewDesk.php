<?php

namespace App\Enums;

use App\Enums\Permission as P;

/**
 * The two review desks a case is sent to at T3 (spec 6.4), with the permission
 * that makes a user eligible for each.
 *
 * Eligibility is by permission. assignmentRole() is only the desk label written
 * to case_assignments.role, using the same Role identifiers as the holderRole
 * labels in TransitionRules; it is never used for an authorisation check.
 */
enum ReviewDesk: string
{
    case Technical = 'technical';
    case Funding = 'funding';

    /** Form and validation field for this desk's chosen user ids. */
    public function field(): string
    {
        return match ($this) {
            self::Technical => 'technocrats',
            self::Funding => 'cab_officers',
        };
    }

    /** A user must hold this permission to be chosen for the desk. */
    public function permission(): P
    {
        return match ($this) {
            self::Technical => P::TechnicalConsider,
            self::Funding => P::FundingConfirm,
        };
    }

    /** case_assignments.role value (a desk label, not a check). */
    public function assignmentRole(): string
    {
        return match ($this) {
            self::Technical => Role::Technocrat->value,
            self::Funding => Role::CabOfficer->value,
        };
    }

    /** The case track this desk completes. */
    public function track(): string
    {
        return match ($this) {
            self::Technical => 'technical_track',
            self::Funding => 'funding_track',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Technical => 'Technical Review',
            self::Funding => 'Funding Confirmation',
        };
    }

    /**
     * The outcomes a reviewer at this desk may record, positive first, the one
     * negative outcome last (Kyle, 2026-11-24: funding has two positive ones).
     *
     * @return list<ReviewOutcome>
     */
    public function outcomes(): array
    {
        return match ($this) {
            self::Technical => [ReviewOutcome::Favourable, ReviewOutcome::NotFavourable],
            self::Funding => [ReviewOutcome::Confirmed, ReviewOutcome::VerifiedByAccounts, ReviewOutcome::NotConfirmed],
        };
    }

    /** The negative outcome (needs a reason). */
    public function negativeOutcome(): ReviewOutcome
    {
        $outcomes = $this->outcomes();

        return end($outcomes);
    }

    public function positiveTrack(): TrackStatus
    {
        return $this === self::Technical ? TrackStatus::FAVOURABLE : TrackStatus::CONFIRMED;
    }

    public function negativeTrack(): TrackStatus
    {
        return $this === self::Technical ? TrackStatus::NOT_FAVOURABLE : TrackStatus::NOT_CONFIRMED;
    }

    /** Audit action for a recorded outcome at this desk. */
    public function auditAction(): AuditAction
    {
        return $this === self::Technical ? AuditAction::CaseTechnicalReviewRecorded : AuditAction::CaseFundingRecorded;
    }

    /** Plural noun for messages, for example "Choose 1 to 3 technocrats." */
    public function people(): string
    {
        return match ($this) {
            self::Technical => 'technocrats',
            self::Funding => 'CAB officers',
        };
    }
}
