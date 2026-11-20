<?php

namespace App\Http\Controllers;

use App\Support\Dashboard;
use App\Support\WorkQueue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing dashboard: one template for every role (spec 12.3). The work queue
 * plus the role's widgets, all chosen by permission and scoped by the case
 * visibility rules; vendors get their own drafts and requests only.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $queue = WorkQueue::for($user);

        return Inertia::render('dashboard', [
            'workQueue' => $queue,
            'widgets' => Dashboard::for($user, $queue),
        ]);
    }
}
