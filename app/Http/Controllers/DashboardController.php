<?php

namespace App\Http\Controllers;

use App\Support\WorkQueue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Landing dashboard: a greeting, and the work queue for users who have one. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', [
            'workQueue' => WorkQueue::for($request->user()),
        ]);
    }
}
