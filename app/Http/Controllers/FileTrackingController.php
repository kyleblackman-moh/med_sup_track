<?php

namespace App\Http\Controllers;

use App\Http\Resources\Cases\StaffCaseDetail;
use App\Models\ProcurementCase;
use App\Workflow\FileTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kyle, 2026-11-24: File Tracking, server side. The list is every case the
 * user can see (the global visibility scope; staff never see drafts); a hidden
 * case's timeline is 404 (route model binding through the same scope).
 *
 * Backlog, 2026-11-25: the File Tracking page. A browser visit to the list
 * renders the page (rows longest at the current stage first, each with the
 * gate's three ticks while in review); a JSON request still gets the JSON. A
 * row's stage timeline is the optional `timeline` prop, sent only on the
 * partial reload that opens it (?open=<case id>), through the same scope and
 * the view check; a case the user cannot see sends null.
 */
class FileTrackingController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $cases = ProcurementCase::query()
            ->with([
                'requisition:id,case_id,item_description',
                'transitions:id,case_id,from_status,to_status,created_at',
                'assignments' => fn ($q) => $q->whereNull('completed_at')->whereNull('cancelled_at'),
                'assignments.user:id,name',
            ])
            ->get();

        if ($request->wantsJson()) {
            return response()->json(['tracking' => FileTracking::rows($cases)]);
        }

        $gates = $cases->mapWithKeys(fn (ProcurementCase $c) => [(string) $c->getKey() => StaffCaseDetail::gateTicks($c)]);

        return Inertia::render('tracking/index', [
            'rows' => array_map(fn (array $row) => [...$row, 'gate' => $gates[$row['id']] ?? null], FileTracking::rows($cases)),
            'timeline' => Inertia::optional(fn () => self::openTimeline($request)),
        ]);
    }

    public function show(ProcurementCase $procurementCase): JsonResponse
    {
        Gate::authorize('view', $procurementCase);

        return response()->json([
            'case' => ['id' => (string) $procurementCase->getKey(), 'reference' => $procurementCase->reference_no],
            'timeline' => FileTracking::timeline($procurementCase),
        ]);
    }

    /** @return array{id: string, stages: list<array<string, mixed>>}|null */
    private static function openTimeline(Request $request): ?array
    {
        $id = $request->query('open');
        if (! is_string($id) || ! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id)) {
            return null;
        }

        $case = ProcurementCase::query()->find($id); // the visibility scope: a hidden case is not found
        if ($case === null || Gate::denies('view', $case)) {
            return null;
        }

        return ['id' => (string) $case->getKey(), 'stages' => FileTracking::timeline($case)];
    }
}
