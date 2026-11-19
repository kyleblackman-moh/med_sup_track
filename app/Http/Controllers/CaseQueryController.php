<?php

namespace App\Http\Controllers;

use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Queries\QueryAccess;
use App\Queries\QueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * OVERNIGHT-2 (D2, D3): query actions. The case is bound through its
 * visibility scope (another organisation's case, a hidden case or a draft is
 * 404), and a query or message must belong to that case (404 otherwise). A
 * query the user cannot see is 404 as well; an action they may not take on a
 * query they can see is 403. QueryService checks everything again.
 */
class CaseQueryController extends Controller
{
    public function store(Request $request, ProcurementCase $procurementCase, QueryService $service): RedirectResponse
    {
        $validated = $request->validate([
            'route' => ['required', 'integer'],
            'body' => ['required', 'string', 'min:'.QueryService::BODY_MIN, 'max:'.QueryService::BODY_MAX],
        ], self::messages() + ['route.required' => 'Choose who the query goes to.', 'route.integer' => 'Choose who the query goes to from the list.']);

        abort_if(QueryAccess::raisableRoutes($request->user(), $procurementCase)->isEmpty(), 403);

        $service->raise($procurementCase, $request->user(), (int) $validated['route'], $validated['body']);

        return back()->with('success', 'Query raised.');
    }

    public function reply(Request $request, ProcurementCase $procurementCase, string $query, QueryService $service): RedirectResponse
    {
        $caseQuery = $this->visibleQuery($request, $procurementCase, $query);
        abort_unless(QueryAccess::canReply($request->user(), $procurementCase, $caseQuery), 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:'.QueryService::BODY_MIN, 'max:'.QueryService::BODY_MAX],
        ], self::messages());

        $service->reply($procurementCase, $caseQuery, $request->user(), $validated['body']);

        return back()->with('success', 'Message sent.');
    }

    /**
     * D3: a vendor's text reply. Another organisation's case is 404 through the
     * binding; an internal, held or unknown query is 404; a closed case or a
     * closed query is 403. Documents still go through the Documents tab.
     */
    public function vendorReply(Request $request, ProcurementCase $procurementCase, string $query, QueryService $service): RedirectResponse
    {
        abort_unless($request->user()->isVendor(), 404);

        return $this->reply($request, $procurementCase, $query, $service);
    }

    public function forward(Request $request, ProcurementCase $procurementCase, string $query, string $message, QueryService $service): RedirectResponse
    {
        $caseQuery = $this->visibleQuery($request, $procurementCase, $query);
        $queryMessage = QueryMessage::query()->where('query_id', $caseQuery->getKey())->find($message);
        abort_if($queryMessage === null || ! QueryAccess::canSeeMessage($request->user(), $procurementCase, $caseQuery, $queryMessage), 404);
        abort_unless(QueryAccess::isCmoDesk($request->user()), 403);

        $service->forward($procurementCase, $caseQuery, $queryMessage, $request->user());

        return back()->with('success', 'Query forwarded.');
    }

    public function close(Request $request, ProcurementCase $procurementCase, string $query, QueryService $service): RedirectResponse
    {
        $caseQuery = $this->visibleQuery($request, $procurementCase, $query);
        abort_unless(QueryAccess::canClose($request->user(), $procurementCase, $caseQuery), 403);

        $service->close($procurementCase, $caseQuery, $request->user());

        return back()->with('success', 'Query closed.');
    }

    /** The query, on this case and visible to the user; 404 otherwise. */
    private function visibleQuery(Request $request, ProcurementCase $case, string $id): CaseQuery
    {
        $query = CaseQuery::withoutGlobalScopes()->where('case_id', $case->getKey())->find($id);
        abort_if($query === null || QueryAccess::visibleMessages($request->user(), $case, $query)->isEmpty(), 404);

        return $query;
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
