<?php

namespace App\Http\Controllers;

use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Queries\QueryAccess;
use App\Queries\QueryService;
use App\Queries\QueryWording;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * OVERNIGHT-2 (D2, D3): query actions. A query or message must belong to the
 * case (404 otherwise), and a query the user cannot see is 404 as well; an
 * action they may not take on a query they can see is 403. QueryService
 * checks everything again.
 *
 * WORKFLOW-2: reply, forward and close bind {queryCase}
 * (QueryAccess::caseForQueryWork), because an officer the CMO named on a
 * forward is a party to that query without being assigned to the case. A user
 * who can neither see the case nor was named on one of its queries still gets
 * 404 at the binding, as before. Raising a query needs the case itself
 * ({procurementCase}, scoped). The vendor's reply keeps the scoped binding
 * (own organisation only).
 */
class CaseQueryController extends Controller
{
    public function store(Request $request, ProcurementCase $procurementCase, QueryService $service): RedirectResponse
    {
        $case = $procurementCase;

        $validated = $request->validate([
            'route' => ['required', 'integer'],
            'body' => ['required', 'string', 'min:'.QueryService::BODY_MIN, 'max:'.QueryService::BODY_MAX],
        ], self::messages() + ['route.required' => 'Choose who the query goes to.', 'route.integer' => 'Choose who the query goes to from the list.']);

        abort_if(QueryAccess::raisableRoutes($request->user(), $case)->isEmpty(), 403);

        $service->raise($case, $request->user(), (int) $validated['route'], $validated['body']);

        return back()->with('success', 'Query raised.');
    }

    public function reply(Request $request, ProcurementCase $queryCase, string $query, QueryService $service): RedirectResponse
    {
        return $this->replyOn($request, $queryCase, $query, $service);
    }

    /**
     * D3: a vendor's text reply. Another organisation's case is 404 through the
     * binding; an internal, held or unknown query is 404; a closed case or a
     * closed query is 403. Documents still go through the Documents tab.
     */
    public function vendorReply(Request $request, ProcurementCase $procurementCase, string $query, QueryService $service): RedirectResponse
    {
        abort_unless($request->user()->isVendor(), 404);

        return $this->replyOn($request, $procurementCase, $query, $service);
    }

    public function forward(Request $request, ProcurementCase $queryCase, string $query, string $message, QueryService $service): RedirectResponse
    {
        $case = $queryCase;
        $caseQuery = $this->visibleQuery($request, $case, $query);
        $queryMessage = QueryMessage::query()->where('query_id', $caseQuery->getKey())->find($message);
        abort_if($queryMessage === null || ! QueryAccess::canSeeMessage($request->user(), $case, $caseQuery, $queryMessage), 404);
        abort_unless(QueryAccess::isCmoDesk($request->user()), 403);

        // WORKFLOW-2: the officer the CMO names (optional unless nobody on that desk is on the case).
        $validated = $request->validate(
            ['officer_id' => ['nullable', 'integer']],
            ['officer_id.integer' => 'Choose the officer from the list.'],
        );

        $service->forward($case, $caseQuery, $queryMessage, $request->user(), isset($validated['officer_id']) ? (int) $validated['officer_id'] : null);

        // Kyle, 2026-11-27: the forward is the CMO's part done, so back to To Do (or the Queries page it came from).
        return redirect()->route(self::doneRoute($request))->with('success', 'Query on '.($case->reference_no ?? 'the case').' forwarded to '.QueryWording::office($queryMessage->recipient_role, $case).'.');
    }

    public function close(Request $request, ProcurementCase $queryCase, string $query, QueryService $service): RedirectResponse
    {
        $case = $queryCase;
        $caseQuery = $this->visibleQuery($request, $case, $query);
        abort_unless(QueryAccess::canClose($request->user(), $case, $caseQuery), 403);

        $service->close($case, $caseQuery, $request->user());

        return back()->with('success', 'Query closed.');
    }

    private function replyOn(Request $request, ProcurementCase $case, string $query, QueryService $service): RedirectResponse
    {
        $caseQuery = $this->visibleQuery($request, $case, $query);
        abort_unless(QueryAccess::canReply($request->user(), $case, $caseQuery), 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:'.QueryService::BODY_MIN, 'max:'.QueryService::BODY_MAX],
        ], self::messages());

        // Kyle, 2026-11-27: a staff answer is that officer's part done, so back to To Do.
        // A follow-up message (and every vendor reply) stays on the page.
        $answering = ! $request->user()->isVendor() && QueryAccess::needsAnswer($request->user(), $case, $caseQuery);

        $service->reply($case, $caseQuery, $request->user(), $validated['body']);

        if ($answering) {
            return redirect()->route(self::doneRoute($request))->with('success', 'Answer sent on '.($case->reference_no ?? 'the case').'.');
        }

        return back()->with('success', 'Message sent.');
    }

    /** The query, on this case and visible to the user; 404 otherwise. */
    private function visibleQuery(Request $request, ProcurementCase $case, string $id): CaseQuery
    {
        $query = CaseQuery::withoutGlobalScopes()->where('case_id', $case->getKey())->find($id);
        abort_if($query === null || QueryAccess::visibleMessages($request->user(), $case, $query)->isEmpty(), 404);

        return $query;
    }

    /** Where a finished forward or answer lands: the Queries page when sent from it, otherwise To Do. */
    private static function doneRoute(Request $request): string
    {
        return $request->input('from') === 'queries' ? 'queries.index' : 'dashboard';
    }

    /** @return array<string, string> */
    private static function messages(): array
    {
        return [
            'body.required' => 'Enter your message.',
            'body.min' => 'Enter your message.',
            'body.max' => 'The message can be at most '.QueryService::BODY_MAX.' characters.',
        ];
    }
}
