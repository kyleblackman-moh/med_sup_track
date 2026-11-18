<?php

namespace App\Http\Controllers;

use App\Http\Resources\Dashboard\DashboardView;
use App\Support\WorkQueue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing dashboard, one page for every role: the header, the stat tiles and
 * the work queue for users who have one. The display props are closures, so a
 * partial reload computes only what it asks for.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $queue = null;
        $loadQueue = function () use (&$queue, $user) {
            return $queue ??= ['value' => WorkQueue::for($user)];
        };

        return Inertia::render('dashboard', [
            'workQueue' => fn () => $loadQueue()['value'],
            'header' => fn () => DashboardView::header($user, $loadQueue()['value']),
            'stats' => fn () => DashboardView::stats($user, $loadQueue()['value']),
            'requestTypes' => fn () => DashboardView::requestTypes($loadQueue()['value']),
        ]);
    }
}
