<?php

namespace App\Http\Controllers;

use App\Enums\CaseStatus;
use App\Http\Resources\Cases\CoverSheet;
use App\Models\ProcurementCase;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FR-03: the printable cover sheet, for the vendor (requests.cover-sheet) and
 * staff (cases.cover-sheet). A case nobody may see is 404 by route model
 * binding; a draft has no reference and no sheet (404).
 */
class CoverSheetController extends Controller
{
    public function vendor(ProcurementCase $procurementCase): Response
    {
        return $this->sheet($procurementCase, route('requests.show', $procurementCase));
    }

    public function staff(ProcurementCase $procurementCase): Response
    {
        return $this->sheet($procurementCase, route('cases.show', $procurementCase));
    }

    private function sheet(ProcurementCase $case, string $back): Response
    {
        Gate::authorize('view', $case);
        abort_if($case->status === CaseStatus::DRAFT || $case->reference_no === null, 404);

        return Inertia::render('cover-sheet', ['sheet' => CoverSheet::for($case), 'back_url' => $back]);
    }
}
