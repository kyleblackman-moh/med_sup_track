<?php

namespace App\Http\Controllers;

use App\Letters\RequestLetter;
use App\Models\ProcurementCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The request letter PDF (Kyle, 2026-11-24). It is the request's print and
 * export output (it replaces a separate browser print page).
 *
 * Vendor: own organisation, any status (a DRAFT carries the watermark). Staff:
 * anyone who can view the case. Another organisation's case, and a DRAFT for
 * staff, is 404 through the global scope on route model binding. A type with no
 * letter (the old Pharm / Non-Pharm forms, no type chosen yet) is 404.
 */
class RequestLetterController extends Controller
{
    public function vendor(Request $request, ProcurementCase $procurementCase): StreamedResponse
    {
        return $this->export($request, $procurementCase, RequestLetter::VENDOR);
    }

    public function staff(Request $request, ProcurementCase $procurementCase): StreamedResponse
    {
        return $this->export($request, $procurementCase, RequestLetter::STAFF);
    }

    private function export(Request $request, ProcurementCase $case, string $audience): StreamedResponse
    {
        Gate::authorize('view', $case);

        $letter = RequestLetter::for($case) ?? abort(404);
        $bytes = $letter->pdf();
        $letter->recordExport($request->user(), $audience);

        return response()->streamDownload(fn () => print ($bytes), $letter->filename(), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
