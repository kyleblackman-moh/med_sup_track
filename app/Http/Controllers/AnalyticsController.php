<?php

namespace App\Http\Controllers;

use App\Http\Resources\Dashboard\AnalyticsCharts;
use App\Http\Resources\Dashboard\DashboardView;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ANALYTICS: charts and tracking for the CMO's office and the PS office
 * (case.view_all holders). The route authorises (viewStaffCases and
 * case.view_all), so everyone else gets 403. The props are closures, so a
 * partial reload computes only what it asks for.
 */
class AnalyticsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('analytics', [
            'stats' => fn () => DashboardView::stats($user),
            'pipeline' => fn () => DashboardView::pipeline($user),
            'gateTracker' => fn () => DashboardView::gateTracker($user),
            'charts' => fn () => AnalyticsCharts::for($user),
        ]);
    }
}
