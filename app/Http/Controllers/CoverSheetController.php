<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Http\Resources\Requests\CoverSheet;
use App\Models\ProcurementCase;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * HARDCOPY-1: the hardcopy cover sheet PDF, generated on demand (no stored
 * file), one audit row per download. The vendor (own organisation: the route
 * binding's visibility scope makes another organisation's case 404) and staff
 * who can view the case. Submitted cases only: a draft has none (404, as the
 * receipt).
 */
class CoverSheetController extends Controller
{
    public function __invoke(Request $request, ProcurementCase $procurementCase): Response
    {
        Gate::authorize('view', $procurementCase);
        abort_if($procurementCase->status === CaseStatus::DRAFT || blank($procurementCase->reference_no), 404);

        Audit::record(
            AuditAction::CoverSheetDownloaded,
            ['type' => 'case', 'id' => $procurementCase->getKey()],
            $procurementCase->getKey(),
            ['by_vendor' => $request->user()->isVendor()],
            $request->user(),
        );

        return Pdf::loadView('pdf.cover-sheet', ['sheet' => CoverSheet::for($procurementCase)])
            ->setPaper('a4')
            ->download(CoverSheet::filename($procurementCase));
    }
}
