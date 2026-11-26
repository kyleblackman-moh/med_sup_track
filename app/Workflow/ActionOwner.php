<?php

namespace App\Workflow;

use App\Enums\CaseStatus;
use App\Enums\QueryStatus;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Enums\UserType;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use Illuminate\Database\Eloquent\Builder;

/**
 * The status pill (Kyle, 2026-11-26): who has to act next, in place of the
 * stage name. Staff and vendor each get a label and a tone; React only maps
 * the tone to classes. The stage itself (CaseStatus::label) stays for history,
 * filters and notification text.
 *
 * Staff, by status (who performs the next transition in TransitionRules):
 * - an OPEN query to the vendor: "{vendor short name} to Action"
 * - SUBMITTED, CMO_INTAKE, READY_FOR_FILE, FILE_PREPARED, RETURNED_TO_CMO,
 *   NON_OBJECTION_GRANTED: "CMO to Action"
 * - IN_REVIEW: the desks still to record ("Technocrats", "CAB", or both);
 *   when neither is (the gate or a negative outcome): "CMO to Action"
 * - WITH_LEGAL: "Legal to Action"; WITH_PS: "PS to Action / Non-Objection"
 * - LETTER_ISSUED, CLOSED, WITHDRAWN, REJECTED_FINAL, DRAFT: the stage label
 *
 * Vendor: CaseStatus::vendorLabel ("Under review" for every internal stage),
 * except "Vendor to view" while a query to the vendor is open.
 */
final class ActionOwner
{
    public const CMO = 'CMO to Action';

    public const TECHNOCRATS = 'Technocrats to Action';

    public const CAB = 'CAB to Action';

    public const TECHNOCRATS_AND_CAB = 'Technocrats and CAB to Action';

    public const LEGAL = 'Legal to Action';

    public const PS = 'PS to Action / Non-Objection';

    public const VENDOR_SUFFIX = ' to Action';

    /** What the vendor sees while a query to it is open. */
    public const VENDOR_VIEW = 'Vendor to view';

    public const VENDOR_TONE = 'warning';

    /** The withExists alias lists use, so a row never costs a query. */
    public const LOADED = 'open_vendor_query';

    /** @return array{label: string, tone: string} */
    public static function staff(ProcurementCase $case): array
    {
        $status = $case->status;
        if (self::isOutcome($status)) {
            return ['label' => $status->label(), 'tone' => $status->tone()];
        }

        if (self::hasOpenVendorQuery($case)) {
            $org = $case->organisation;
            $name = trim((string) ($org?->short_name ?: $org?->name));

            return ['label' => ($name !== '' ? $name : 'Vendor').self::VENDOR_SUFFIX, 'tone' => self::VENDOR_TONE];
        }

        return match ($status) {
            CaseStatus::IN_REVIEW => self::review($case),
            CaseStatus::WITH_LEGAL => ['label' => self::LEGAL, 'tone' => 'purple'],
            CaseStatus::WITH_PS => ['label' => self::PS, 'tone' => 'fuchsia'],
            default => ['label' => self::CMO, 'tone' => 'indigo'],
        };
    }

    /** @return array{label: string, tone: string} */
    public static function vendor(ProcurementCase $case): array
    {
        if (! self::isOutcome($case->status) && self::hasOpenVendorQuery($case)) {
            return ['label' => self::VENDOR_VIEW, 'tone' => self::VENDOR_TONE];
        }

        return ['label' => $case->status->vendorLabel(), 'tone' => $case->status->vendorTone()];
    }

    /** @return array{label: string, tone: string} */
    public static function for(ProcurementCase $case, bool $vendorView): array
    {
        return $vendorView ? self::vendor($case) : self::staff($case);
    }

    /**
     * Adds the open-vendor-query flag to a list query (read by hasOpenVendorQuery).
     * A count, not withExists: SQL Server has no EXISTS in a select list. The
     * cases query is already scoped to the viewer, so the queries' own scope is dropped.
     */
    public static function withOpenVendorQuery(Builder $cases): Builder
    {
        return $cases->withCount(['queries as '.self::LOADED => fn (Builder $q) => self::openToVendor($q->withoutGlobalScopes())]);
    }

    public static function hasOpenVendorQuery(ProcurementCase $case): bool
    {
        $loaded = $case->getAttribute(self::LOADED);
        if ($loaded !== null) {
            return (bool) $loaded;
        }

        return self::openToVendor(CaseQuery::withoutGlobalScopes()->where('case_id', $case->getKey()))->exists();
    }

    /** Statuses that are an outcome, not a step: the pill keeps the stage label. */
    private static function isOutcome(CaseStatus $status): bool
    {
        return in_array($status, [CaseStatus::DRAFT, CaseStatus::LETTER_ISSUED, CaseStatus::CLOSED, CaseStatus::WITHDRAWN, CaseStatus::REJECTED_FINAL], true);
    }

    /** @return array{label: string, tone: string} */
    private static function review(ProcurementCase $case): array
    {
        $technical = self::deskToAct($case->technical_track);
        $funding = self::deskToAct($case->funding_track);

        return match (true) {
            $technical && $funding => ['label' => self::TECHNOCRATS_AND_CAB, 'tone' => 'violet'],
            $technical => ['label' => self::TECHNOCRATS, 'tone' => 'violet'],
            $funding => ['label' => self::CAB, 'tone' => 'teal'],
            default => ['label' => self::CMO, 'tone' => 'indigo'],
        };
    }

    /** The desk has not recorded an outcome yet (a query it raised is answered, or still open elsewhere). */
    private static function deskToAct(?TrackStatus $track): bool
    {
        return $track === null || $track === TrackStatus::PENDING || $track === TrackStatus::QUERY_OPEN;
    }

    private static function openToVendor(Builder $queries): Builder
    {
        $vendorRoles = array_values(array_map(
            fn (Role $r) => $r->value,
            array_filter(Role::cases(), fn (Role $r) => $r->userType() === UserType::Vendor),
        ));

        return $queries->where('status', QueryStatus::OPEN->value)->whereIn('raised_to_role', $vendorRoles);
    }
}
