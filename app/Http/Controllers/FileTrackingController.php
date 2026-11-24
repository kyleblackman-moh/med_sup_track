<?php

namespace App\Http\Controllers;

use App\Models\ProcurementCase;
use App\Workflow\FileTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Kyle, 2026-11-24: File Tracking, server side. JSON until the page comes with
 * the UI. The list is every case the user can see (the global visibility
 * scope; staff never see drafts); a hidden case's timeline is 404 (route
 * model binding through the same scope).
 */
class FileTrackingController extends Controller
{
    public function index(): JsonResponse
    {
        $cases = ProcurementCase::query()
            ->with([
                'requisition:id,case_id,item_description',
                'transitions:id,case_id,from_status,to_status,created_at',
                'assignments' => fn ($q) => $q->whereNull('completed_at')->whereNull('cancelled_at'),
                'assignments.user:id,name',
            ])
            ->get();

        return response()->json(['tracking' => FileTracking::rows($cases)]);
    }

    public function show(ProcurementCase $procurementCase): JsonResponse
    {
        Gate::authorize('view', $procurementCase);

        return response()->json([
            'case' => ['id' => (string) $procurementCase->getKey(), 'reference' => $procurementCase->reference_no],
            'timeline' => FileTracking::timeline($procurementCase),
        ]);
    }
}
