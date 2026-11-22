<?php

namespace App\Http\Controllers;

use App\Http\Middleware\HandleInertiaRequests;
use App\Queries\QueryAccess;
use App\Queries\QueryInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * QUERIES-1: the sidebar's Queries page. The queries waiting on the signed-in
 * user (to forward or to answer), oldest first, each linking to the place it is
 * answered: the case's Queries tab for staff, the request page for vendors.
 * Only rows QueryInbox finds for this user; nothing else of any case.
 * Filter (?filter=): all, answer or forward.
 */
class QueryInboxController extends Controller
{
    public const FILTERS = ['all', 'answer', 'forward'];

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        // A missing permission is 403 (no case is involved).
        abort_unless(HandleInertiaRequests::queriesNav($user), 403);

        $validator = Validator::make($request->query(), [
            'filter' => ['nullable', 'string', Rule::in(self::FILTERS)],
        ]);
        // A bad filter is reported, never silently ignored (as on the case list).
        if ($validator->fails()) {
            return to_route('queries.index')->withErrors($validator);
        }
        $filter = $validator->validated()['filter'] ?? 'all';

        $rows = QueryInbox::rows($user);
        $answer = array_values(array_filter($rows, fn (array $r) => $r['step'] === QueryInbox::ANSWER));
        $forward = array_values(array_filter($rows, fn (array $r) => $r['step'] === QueryInbox::FORWARD));

        return Inertia::render('queries/index', [
            'queries' => match ($filter) {
                'answer' => $answer,
                'forward' => $forward,
                default => $rows,
            },
            'filter' => $filter,
            'counts' => ['all' => count($rows), 'answer' => count($answer), 'forward' => count($forward)],
            // Show/hide only: the To Forward filter is for the CMO's desk.
            'forward_desk' => QueryAccess::isCmoDesk($user),
        ]);
    }
}
