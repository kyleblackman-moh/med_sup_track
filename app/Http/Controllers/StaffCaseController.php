<?php

namespace App\Http\Controllers;

use App\Documents\DocumentDownloadLinks;
use App\Enums\CaseStatus;
use App\Enums\OrganisationType;
use App\Enums\Permission;
use App\Enums\ScanStatus;
use App\Http\Resources\Cases\StaffCaseDetail;
use App\Http\Resources\Cases\StaffCaseSummary;
use App\Models\Document;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only staff case pages (slice 9a). Nothing here changes a case.
 *
 * The routes require CasePolicy::viewStaffCases (403 otherwise). Which cases
 * appear is VisibleToUserScope's decision alone: route model binding goes
 * through the global scope, so a hidden or DRAFT case is 404.
 */
class StaffCaseController extends Controller
{
    private const PER_PAGE = 25;

    private const SORTS = ['submitted_at', 'reference_no', 'status'];

    public function index(Request $request): Response|RedirectResponse
    {
        $statuses = array_values(array_filter(CaseStatus::cases(), fn (CaseStatus $s) => $s !== CaseStatus::DRAFT));

        $validator = Validator::make($request->query(), [
            'status' => ['nullable', 'string', Rule::in(array_map(fn (CaseStatus $s) => $s->value, $statuses))],
            'type' => ['nullable', 'integer', Rule::exists('procurement_types', 'id')],
            'org' => ['nullable', 'string', 'max:26', Rule::exists('organisations', 'id')->where('type', OrganisationType::Vendor->value)],
            // Letters, digits, '-' and '/' only, so the LIKE pattern needs no escaping.
            'ref' => ['nullable', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9\/-]+$/'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTS)],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'ref.min' => 'Enter at least 3 characters of the reference.',
            'ref.regex' => 'The reference can contain only letters, numbers, "-" and "/".',
        ]);

        // A bad filter is reported, never silently ignored. Redirect to the
        // unfiltered list (not back(), which could be the same bad URL).
        if ($validator->fails()) {
            return to_route('cases.index')->withErrors($validator);
        }

        $filters = $validator->validated();
        $sort = $filters['sort'] ?? 'submitted_at';
        $dir = $filters['dir'] ?? 'desc';

        $page = ProcurementCase::query()
            ->with(['organisation:id,name', 'procurementType:id,name', 'requisition:id,case_id,item_description'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('cases.status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('cases.procurement_type_id', $v))
            ->when($filters['org'] ?? null, fn ($q, $v) => $q->where('cases.org_id', $v))
            ->when($filters['ref'] ?? null, fn ($q, $v) => $q->where('cases.reference_no', 'like', "%{$v}%"))
            ->orderBy("cases.{$sort}", $dir)
            ->orderBy('cases.id', $dir)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $today = CarbonImmutable::now(StaffCaseSummary::TIMEZONE)->startOfDay();

        return Inertia::render('cases/index', [
            'cases' => [
                'data' => $page->getCollection()->map(fn (ProcurementCase $c) => StaffCaseSummary::fields($c, $today))->values()->all(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'prev_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ],
            'filters' => [
                'status' => $filters['status'] ?? '',
                'type' => isset($filters['type']) ? (string) $filters['type'] : '',
                'org' => $filters['org'] ?? '',
                'ref' => $filters['ref'] ?? '',
                'sort' => $sort,
                'dir' => $dir,
            ],
            'options' => [
                'statuses' => array_map(fn (CaseStatus $s) => ['value' => $s->value, 'label' => $s->label()], $statuses),
                'types' => ProcurementType::orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (ProcurementType $t) => ['value' => (string) $t->id, 'label' => $t->name])->all(),
                'organisations' => Organisation::where('type', OrganisationType::Vendor->value)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Organisation $o) => ['value' => $o->id, 'label' => $o->name])->all(),
            ],
        ]);
    }

    public function show(Request $request, ProcurementCase $procurementCase): Response
    {
        Gate::authorize('view', $procurementCase);

        return Inertia::render('cases/show', StaffCaseDetail::for($procurementCase, $request->user()));
    }

    /**
     * Same flow as the vendor download (slice 8c): audit, then a 60-second link
     * signed for this user. The document is bound through the case, so a
     * document of another case is 404.
     */
    public function download(Request $request, ProcurementCase $procurementCase, Document $document, DocumentDownloadLinks $links): RedirectResponse
    {
        abort_unless($request->user()->can(Permission::DocumentDownload->value), 403);
        abort_unless($document->scan_status === ScanStatus::CLEAN && Gate::allows('download', $document), 404);

        return redirect()->away($links->issue($document, $request->user()));
    }
}
