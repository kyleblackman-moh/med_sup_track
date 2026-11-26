<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Resources\Dashboard\DashboardView;
use App\Support\WorkQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 * Kyle, 2026-11-26: the page is "To Do": only the tasks waiting on the user,
 * under their desk headings, most recent first. The File Tracking board left
 * (the File Tracking page has it) and the hardcopy reference box moved to the
 * Application Requests list (StaffCaseController::index).
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
        ]);
    }

    /** Vendors who submit requests: their home is the Requests list (Kyle, 2026-11-24). */
    public static function requestsHome($user): bool
    {
        return $user !== null && $user->isVendor() && $user->can(Permission::CaseSubmit->value);
    }
}
