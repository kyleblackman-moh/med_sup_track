<?php

namespace App\Queries;

use App\Enums\Role;
use App\Models\ProcurementCase;
use App\Models\QueryRoute;
use App\Workflow\StageWording;

/**
 * OVERNIGHT-2 (D2): every word the query screens use, on the server. A
 * route's roles are shown as the office the Next Step card already names
 * (StageWording); the vendor is the case's organisation name.
 */
final class QueryWording
{
    public const MINISTRY = 'Ministry of Health';

    /** The office a route role stands for ("Technocrats", "Legal Unit", ...). */
    public static function office(string $role, ?ProcurementCase $case = null): string
    {
        if (QueryAccess::isVendorRole($role)) {
            return (string) ($case?->organisation?->name ?? 'the vendor');
        }

        return match (Role::tryFrom($role)) {
            Role::Technocrat => StageWording::TECHNOCRATS,
            Role::CabOfficer => StageWording::CAB,
            Role::LegalOfficer => StageWording::LEGAL_UNIT,
            Role::PsOffice => StageWording::PS_OFFICE,
            Role::Cmo, Role::CmoRegistry => StageWording::CMO_OFFICE,
            default => Role::tryFrom($role)?->label() ?? $role,
        };
    }

    /** "Legal Query to the Technocrats, via the CMO's Office" for the raise form. */
    public static function routeLabel(QueryRoute $route, ProcurementCase $case): string
    {
        return $route->query_type->label().' to '.self::office($route->to_role, $case)
            .($route->via_cmo ? ', via the '.StageWording::CMO_OFFICE : ', direct');
    }

    public static function forwardLabel(string $toRole, ProcurementCase $case): string
    {
        return 'Forward Query to '.self::office($toRole, $case);
    }
}
