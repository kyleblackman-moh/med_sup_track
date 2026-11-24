<?php

namespace App\Http\Resources\Requests;

use App\Enums\AuditAction;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Support\Display;
use Carbon\CarbonImmutable;

/**
 * Kyle, 2026-11-24: the printable request form, server side. The page and its
 * print stylesheet come with the UI; this is the payload.
 *
 * The form exactly as filled: every visible section of the type, in order,
 * with every value and the computed figures, from the one RequestSummary
 * (Subtotal, VAT and Total Price per quotation and the total cost, days on
 * hand, the % of each award, the quantity outstanding per order). Documents:
 * the external, clean files only, for staff too. A header: reference, type,
 * status, organisation and when it was printed (Port of Spain).
 *
 * The same request form for vendor and staff. Nothing internal is in it for
 * either: no CAB funding status or reason, no review outcomes, no internal
 * documents or notes, no query messages (RequestSummary carries none of these).
 * The vendor header uses the vendor status label. Each print is audited
 * (case.printed, the audience only).
 *
 * The CALLER authorises (view the case). Null for a case not on the request
 * form (the old Pharm and Non-Pharm types).
 */
final class PrintForm
{
    private const TIMEZONE = 'America/Port_of_Spain';

    public const VENDOR = 'vendor';

    public const STAFF = 'staff';

    /** @return array{header: array<string, string>, type_label: string, sections: list<array<string, mixed>>}|null */
    public static function for(ProcurementCase $case, User $viewer, string $audience, ?CarbonImmutable $now = null): ?array
    {
        $case->loadMissing(['procurementType', 'organisation']);
        $summary = RequestSummary::for($case, $viewer, forStaff: $audience === self::STAFF, withDocuments: true, forPrint: true);

        if ($summary === null) {
            return null;
        }

        Audit::record(
            AuditAction::CasePrinted,
            ['type' => 'case', 'id' => $case->getKey()],
            $case->getKey(),
            ['audience' => $audience],
            $viewer,
        );

        $printed = ($now ?? CarbonImmutable::now('UTC'))->setTimezone(self::TIMEZONE);

        return [
            'header' => [
                'reference' => $case->reference_no ?? Display::EMPTY, // a draft has none yet
                'type' => $summary['type_label'],
                'status' => $audience === self::VENDOR ? $case->status->vendorLabel() : $case->status->label(),
                'organisation' => (string) $case->organisation?->name,
                'printed_at' => $printed->format('j M Y, g:i a'),
                'printed_at_iso' => $printed->toIso8601String(),
            ],
            ...$summary,
        ];
    }
}
