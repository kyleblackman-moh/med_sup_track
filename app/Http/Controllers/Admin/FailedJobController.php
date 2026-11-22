<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\FailedJobView;
use App\Support\Audit;
use App\Support\SystemHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * IT-ADMIN Part 3: failed queue jobs. The list needs system.health.view (in
 * the console: 404 otherwise); Retry needs jobs.retry (403 inside the
 * console) and a recently confirmed password, and is audited as job.retried
 * (uuid, job class, queue, connection). Retrying pushes the job back onto
 * its queue through Laravel's own queue:retry, which removes the
 * failed_jobs row; that row is the queue's working state, not a record.
 */
class FailedJobController extends Controller
{
    private const PER_PAGE = 25;

    public function index(): Response
    {
        $page = DB::table(SystemHealth::failedJobsTable())
            ->orderByDesc('failed_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return Inertia::render('admin/jobs/index', [
            'jobs' => [
                'data' => collect($page->items())->map(fn ($job) => FailedJobView::row($job))->all(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'prev_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ],
            'abilities' => [
                'retry' => request()->user()->can(Permission::JobsRetry->value),
            ],
        ]);
    }

    public function retry(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()->can(Permission::JobsRetry->value), 403);

        $job = DB::table(SystemHealth::failedJobsTable())->where('uuid', $uuid)->first() ?? abort(404);
        $view = FailedJobView::row($job);

        DB::transaction(function () use ($uuid, $view) {
            Audit::record(AuditAction::JobRetried, ['type' => 'failed_job', 'id' => $uuid], null, [
                'uuid' => $uuid,
                'job' => $view['job'],
                'queue' => $view['queue'],
                'connection' => $view['connection'],
            ]);

            Artisan::call('queue:retry', ['id' => [$uuid]]);
        });

        SystemHealth::forget();

        return back()->with('success', 'The job was put back on its queue.');
    }
}
