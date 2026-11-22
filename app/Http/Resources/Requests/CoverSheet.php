<?php

namespace App\Http\Resources\Requests;

use App\Models\Document;
use App\Models\Organisation;
use App\Models\ProcurementCase;

/**
 * HARDCOPY-1: the hardcopy cover sheet (vendor-safe). Built field by field:
 * the reference, the vendor organisation's name, the request type, when it
 * was submitted (Port of Spain) and how many documents were uploaded. No
 * internal status, no staff names, no document names. Rendered on demand
 * (resources/views/pdf/cover-sheet.blade.php); never stored.
 */
final class CoverSheet
{
    public const TITLE = 'Hardcopy Cover Sheet – Non-Objection Request';

    public const INSTRUCTION = 'Print this sheet and attach it to the front of your hardcopy submission. Deliver to the Office of the CMO within 24 hours of online submission.';

    /** @return array{title: string, reference: string, organisation: string|null, request_type: string|null, submitted_at: string|null, documents: int, instruction: string} */
    public static function for(ProcurementCase $case): array
    {
        return [
            'title' => self::TITLE,
            'reference' => (string) $case->reference_no,
            'organisation' => Organisation::whereKey($case->org_id)->value('name'),
            'request_type' => $case->procurementType?->name,
            'submitted_at' => $case->submitted_at?->copy()->setTimezone('America/Port_of_Spain')->format('j F Y, g:i a'),
            'documents' => Document::withoutGlobalScopes()->current()->where('case_id', $case->getKey())->count(),
            'instruction' => self::INSTRUCTION,
        ];
    }

    /** The download's file name: the reference, filesystem-safe. */
    public static function filename(ProcurementCase $case): string
    {
        return 'cover-sheet-'.preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $case->reference_no).'.pdf';
    }
}
