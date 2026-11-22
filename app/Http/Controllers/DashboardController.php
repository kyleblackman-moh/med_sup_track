<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Resources\Dashboard\DashboardDrafts;
use App\Http\Resources\Dashboard\DashboardView;
use App\Support\WorkQueue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing dashboard, one page for every role: the header and the work queue
 * for staff who have one, and the user's own drafts for users who submit
 * requests. Stat tiles, the pipeline and the gate tracker live on the
 * Analytics page (AnalyticsController). The display props are closures, so a
 * partial reload computes only what it asks for.
 *
 * DASH-1: the queue card's filter (?filter=) and search (?q=) are applied on
 * the server (DashboardView::queue); the chips and the search box reload only
 * the "queue" prop. "workQueue" stays the unfiltered queue (WorkQueue::for).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $filter = (string) $request->query('filter', '');
        $search = mb_substr(trim((string) $request->query('q', '')), 0, DashboardView::SEARCH_MAX);
        $queue = null;
        $loadQueue = function () use (&$queue, $user) {
            return $queue ??= ['value' => WorkQueue::for($user)];
        };

        return Inertia::render('dashboard', [
            'workQueue' => fn () => $loadQueue()['value'],
            'header' => fn () => DashboardView::header($user, $loadQueue()['value']),
            'requestTypes' => fn () => DashboardView::requestTypes($loadQueue()['value']),
            'queue' => fn () => DashboardView::queue($user, $loadQueue()['value'], $filter, $search),
            // ANALYTICS: the user's own organisation's drafts; null without case.submit.
            'drafts' => fn () => DashboardDrafts::for($user),
            // FR-04: the reference lookup box (registry and CMO); show/hide only, the lookup authorises itself.
            'hardcopyLookup' => ! $user->isVendor() && $user->can(Permission::HardcopyConfirm->value),
        ]);
    }
}
