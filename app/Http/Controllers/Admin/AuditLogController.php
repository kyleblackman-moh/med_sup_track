<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AuditLogView;
use App\Models\AuditLog;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\AccessDenials;
use App\Support\Audit;
use App\Support\AuditCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * IT-ADMIN: the audit log viewer. READ-ONLY: there is no create, edit or
 * delete endpoint anywhere (AuditLogViewerTest checks the route table).
 *
 * Two doors, one page: /admin/audit inside the IT Admin console, and /audit
 * for the CMO and Legal. Both need audit.view; anyone else (the Auditor,
 * vendors, other staff) gets 404.
 *
 * Filters (each validated; a bad one is reported, never ignored): actor (a
 * user id, an email, "system" or "anonymous"), category or action, case
 * reference, date range (Port of Spain days), outcome (allowed/denied).
 * Newest first, cursor-paginated on (occurred_at, id) so a page costs the
 * same at any depth; each filter maps onto an existing index ((actor_user_id,
 * occurred_at), (action, occurred_at), (case_id, occurred_at), occurred_at).
 *
 * Viewing is audited as log.viewed: once per distinct filter set (paging
 * through the same results is not logged again), at most
 * admin.audit.viewed_log_max a window per user.
 */
class AuditLogController extends Controller
{
    public const TIMEZONE = 'America/Port_of_Spain';

    private const SESSION_LAST_VIEW = 'audit_viewer.last_filters';

    public function index(Request $request): Response|RedirectResponse
    {
        $this->authorizeOr404($request);

        $validator = Validator::make($request->query(), [
            'actor' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', Rule::in(array_keys(AuditCatalogue::CATEGORIES))],
            'action' => ['nullable', 'string', Rule::in(AuditCatalogue::actions())],
            'case' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'outcome' => ['nullable', 'string', Rule::in(['allowed', 'denied'])],
            'cursor' => ['nullable', 'string', 'max:500'],
        ], [
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ]);

        if ($validator->fails()) {
            return redirect()->route($this->routeName($request, 'index'))->withErrors($validator);
        }

        $filters = $validator->validated();
        $resolved = $this->resolve($filters);

        $page = $this->query($resolved)
            ->cursorPaginate((int) config('admin.audit.per_page', 50), ['*'], 'cursor', $filters['cursor'] ?? null)
            ->withQueryString();

        $this->recordView($request, $resolved);

        return Inertia::render('audit/index', [
            'rows' => collect($page->items())->map(fn (AuditLog $row) => AuditLogView::row($row))->values()->all(),
            'next_page_url' => $page->nextPageUrl(),
            'prev_page_url' => $page->previousPageUrl(),
            'filters' => [
                'actor' => $filters['actor'] ?? '',
                'category' => $filters['category'] ?? '',
                'action' => $filters['action'] ?? '',
                'case' => $filters['case'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'outcome' => $filters['outcome'] ?? '',
            ],
            'options' => [
                'categories' => AuditCatalogue::categoryOptions(),
                'actions' => AuditCatalogue::actionOptions(),
                'outcomes' => AuditCatalogue::outcomeOptions(),
            ],
            // Which door: the console (admin.audit.*) or the staff page (audit.*). Display only.
            'route_base' => $this->routeName($request, ''),
        ]);
    }

    /** One row's detail, metadata redacted (AuditRedactor). JSON for the row drawer. */
    public function show(Request $request, int $auditLog): JsonResponse
    {
        $this->authorizeOr404($request);

        $row = $this->baseQuery()->where('audit_logs.id', $auditLog)->first() ?? abort(404);

        return response()->json(AuditLogView::detail($row));
    }

    /** audit.view, else 404 (Kyle: the log is not advertised to anyone else). */
    private function authorizeOr404(Request $request): void
    {
        if (! $request->user()?->can(Permission::AuditView->value)) {
            AccessDenials::record($request, 404, 'audit_view');
            abort(404);
        }
    }

    /**
     * Turn the typed filters into ids and bounds. A filter that matches
     * nothing (an unknown email or case reference) gives an empty result, not
     * an unfiltered one.
     *
     * @return array<string, mixed>
     */
    private function resolve(array $filters): array
    {
        $resolved = [];

        if (($actor = trim((string) ($filters['actor'] ?? ''))) !== '') {
            $resolved += match (true) {
                in_array(Str::lower($actor), ['system', 'anonymous'], true) => ['actor_type' => Str::lower($actor)],
                ctype_digit($actor) => ['actor_user_id' => (int) $actor],
                default => ['actor_user_id' => (int) (User::query()->where('email', Str::lower($actor))->value('id') ?? 0)],
            };
        }

        if (($case = trim((string) ($filters['case'] ?? ''))) !== '') {
            // Every case, whatever its status: the audit log covers all of them.
            $resolved['case_id'] = ProcurementCase::withoutGlobalScopes()
                ->where(fn ($q) => $q->where('reference_no', $case)->orWhere('id', $case))
                ->value('id') ?? '';
        }

        foreach (['category', 'action', 'outcome'] as $key) {
            if (! empty($filters[$key])) {
                $resolved[$key] = $filters[$key];
            }
        }

        if (! empty($filters['from'])) {
            $resolved['from'] = CarbonImmutable::createFromFormat('Y-m-d', $filters['from'], self::TIMEZONE)->startOfDay()->utc();
        }
        if (! empty($filters['to'])) {
            $resolved['to'] = CarbonImmutable::createFromFormat('Y-m-d', $filters['to'], self::TIMEZONE)->addDay()->startOfDay()->utc();
        }

        return $resolved;
    }

    /** @return Builder<AuditLog> */
    private function query(array $f): Builder
    {
        return $this->baseQuery()
            ->when(isset($f['actor_type']), fn ($q) => $q->where('audit_logs.actor_type', $f['actor_type']))
            ->when(isset($f['actor_user_id']), fn ($q) => $q->where('audit_logs.actor_user_id', $f['actor_user_id']))
            ->when(isset($f['case_id']), fn ($q) => $q->where('audit_logs.case_id', $f['case_id']))
            ->when(isset($f['action']), fn ($q) => $q->where('audit_logs.action', $f['action']))
            // A category is a fixed list of actions: an IN on the indexed column, never a LIKE.
            ->when(isset($f['category']) && ! isset($f['action']), fn ($q) => $q->whereIn('audit_logs.action', AuditCatalogue::actionsIn($f['category']) ?: ['']))
            ->when(($f['outcome'] ?? null) === 'denied', fn ($q) => $q->whereIn('audit_logs.action', AuditCatalogue::deniedActions()))
            ->when(($f['outcome'] ?? null) === 'allowed', fn ($q) => $q->whereNotIn('audit_logs.action', AuditCatalogue::deniedActions()))
            ->when(isset($f['from']), fn ($q) => $q->where('audit_logs.occurred_at', '>=', $f['from']->format('Y-m-d H:i:s.v')))
            ->when(isset($f['to']), fn ($q) => $q->where('audit_logs.occurred_at', '<', $f['to']->format('Y-m-d H:i:s.v')))
            ->orderByDesc('audit_logs.occurred_at')
            ->orderByDesc('audit_logs.id');
    }

    /** @return Builder<AuditLog> */
    private function baseQuery(): Builder
    {
        return AuditLog::query()
            ->leftJoin('users as actor', 'actor.id', '=', 'audit_logs.actor_user_id')
            ->leftJoin('cases', 'cases.id', '=', 'audit_logs.case_id')
            ->select([
                'audit_logs.*',
                'actor.name as actor_name',
                'actor.email as actor_email',
                'cases.reference_no as case_reference',
            ]);
    }

    /**
     * log.viewed, once per distinct filter set (the cursor does not count),
     * capped per user. Best-effort: a failed write never blocks the page.
     * The metadata holds the resolved filters (ids, codes, dates), never the
     * text typed into the actor or case box.
     */
    private function recordView(Request $request, array $resolved): void
    {
        $filters = array_map(fn ($v) => $v instanceof CarbonImmutable ? $v->toIso8601ZuluString() : $v, $resolved);
        ksort($filters);
        $hash = hash('sha256', json_encode($filters));

        if ($request->session()->get(self::SESSION_LAST_VIEW) === $hash) {
            return;
        }
        $request->session()->put(self::SESSION_LAST_VIEW, $hash);

        $key = 'audit-viewed|'.$request->user()->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('admin.audit.viewed_log_max', 30)))) {
            return;
        }
        RateLimiter::hit($key, 60 * max(1, (int) config('admin.audit.viewed_log_decay_minutes', 10)));

        Audit::recordBestEffort(AuditAction::LogViewed, null, [
            'via' => $request->routeIs('admin.*') ? 'admin' : 'staff',
            'filters' => $filters === [] ? (object) [] : $filters,
        ]);
    }

    private function routeName(Request $request, string $suffix): string
    {
        $base = $request->routeIs('admin.*') ? 'admin.audit.' : 'audit.';

        return $suffix === '' ? rtrim($base, '.') : $base.$suffix;
    }
}
