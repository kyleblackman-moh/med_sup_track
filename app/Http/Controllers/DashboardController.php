<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Resources\Dashboard\DashboardView;
use App\Models\ProcurementCase;
use App\Support\WorkQueue;
use App\Workflow\ActionOwner;
use App\Workflow\FileTracking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing dashboard. Stat tiles, the pipeline and the gate tracker live on the
 * Analytics page (AnalyticsController). The display props are closures, so a
 * partial reload computes only what it asks for.
 *
 * Kyle, 2026-11-24:
 * - Vendors' dashboard is their Requests list: /dashboard sends them there.
 * - Staff get what needs their action (the work queue) and, with File
 *   Tracking, the File Tracking board: where each open file is, when it last
 *   arrived there and its days at every unit. The greeting moved to the top
 *   bar (beside the bell, with a live clock); the hardcopy reference box left.
 *
 * DASH-1: the queue card's filter (?filter=) and search (?q=) are applied on
 * the server (DashboardView::queue); the chips and the search box reload only
 * the "queue" prop. "workQueue" stays the unfiltered queue (WorkQueue::for).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (self::requestsHome($user)) {
            return redirect()->route('requests.index');
        }

        $filter = (string) $request->query('filter', '');
        $search = mb_substr(trim((string) $request->query('q', '')), 0, DashboardView::SEARCH_MAX);
        $queue = null;
        // Backlog, 2026-11-25: "Needs My Action" for every staff user; one with no queue
        // permission gets an empty queue (its empty state), never the old destination cards.
        $loadQueue = function () use (&$queue, $user) {
            return $queue ??= ['value' => WorkQueue::for($user) ?? ($user->isVendor() ? null : ['groups' => [], 'items' => [], 'truncated' => false])];
        };

        return Inertia::render('dashboard', [
            'workQueue' => fn () => $loadQueue()['value'],
            'summary' => fn () => DashboardView::summary($loadQueue()['value']),
            'requestTypes' => fn () => DashboardView::requestTypes($loadQueue()['value']),
            'queue' => fn () => DashboardView::queue($user, $loadQueue()['value'], $filter, $search),
            'tracking' => fn () => self::tracking($user),
            // Backlog, 2026-11-25 (HARDCOPY-1 restored): the "Hardcopy received" reference box for
            // hardcopy.confirm holders (show/hide only; the lookup and the mark authorise).
            'hardcopyLookup' => ! $user->isVendor() && $user->can(Permission::HardcopyConfirm->value),
        ]);
    }

    /** Vendors who submit requests: their home is the Requests list (Kyle, 2026-11-24). */
    public static function requestsHome($user): bool
    {
        return $user !== null && $user->isVendor() && $user->can(Permission::CaseSubmit->value);
    }

    /** The File Tracking board, for File Tracking holders only (the same checks as /tracking); null otherwise. */
    private static function tracking($user): ?array
    {
        if ($user->isVendor() || ! Gate::forUser($user)->allows('viewStaffCases', ProcurementCase::class)
            || ! $user->can(Permission::TrackingView->value)) {
            return null;
        }

        // The global scope keeps it to the cases this user can see (staff never see drafts).
        $cases = ActionOwner::withOpenVendorQuery(ProcurementCase::query())
            ->with([
                'organisation:id,name,short_name', // the pill: "{vendor} to Action"
                'requisition:id,case_id,item_description,din',
                'transitions:id,case_id,from_status,to_status,created_at',
                'assignments' => fn ($q) => $q->whereNull('completed_at')->whereNull('cancelled_at'),
                'assignments.user:id,name',
            ])
            ->get();

        return FileTracking::board($cases);
    }
}
