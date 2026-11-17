<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Http\Resources\Requests\AdHocFormConfig;
use App\Http\Resources\Requests\AdHocSections;
use App\Http\Resources\Requests\FormConfig;
use App\Http\Resources\Requests\ReadinessResource;
use App\Http\Resources\Requests\RequestDetailResource;
use App\Http\Resources\Requests\RequestDocuments;
use App\Http\Resources\Requests\RequestReceipt;
use App\Http\Resources\Requests\RequestSummaryResource;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Submissions\CaseEraser;
use App\Submissions\CreateDraftCase;
use App\Submissions\Exceptions\DraftAlreadyDeleted;
use App\Submissions\Exceptions\DraftNotEditable;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequisitionData;
use App\Submissions\SaveRequisition;
use App\Submissions\SubmissionReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vendor request pages. Thin: authorisation is the policy's, rules are the
 * 8a actions'. Every page gets resources, never raw models. Hidden cases are
 * 404 through ProcurementCase's global scope on route model binding.
 */
class RequestController extends Controller
{
    private const PERMISSION = Permission::CaseSubmit;

    public function index(Request $request): Response
    {
        $page = ProcurementCase::query()
            ->with(['procurementType', 'requisition'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(20);

        return Inertia::render('requests/index', [
            'requests' => [
                'data' => RequestSummaryResource::collection($page->getCollection())->resolve($request),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'prev_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ],
            'can' => $this->can($request, ['create' => $request->user()->can(self::PERMISSION->value)]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('requests/create', [
            'types' => ProcurementType::where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (ProcurementType $t) => [
                    'id' => $t->id,
                    'code' => $t->code,
                    'name' => $t->name,
                    'category' => $t->category?->value, // null for AD_HOC
                ])->all(),
        ]);
    }

    public function store(Request $request, CreateDraftCase $createDraft): RedirectResponse
    {
        // Only the type is read; anything else in the request is ignored.
        $validated = $request->validate([
            'procurement_type_id' => [
                'required', 'integer',
                Rule::exists('procurement_types', 'id')->where('is_active', true),
            ],
        ]);

        $case = $createDraft->handle($request->user(), ProcurementType::findOrFail($validated['procurement_type_id']));

        return to_route('requests.edit', $case);
    }

    public function show(Request $request, ProcurementCase $procurementCase, SubmissionReadiness $readiness): Response
    {
        Gate::authorize('view', $procurementCase);

        $isDraft = $procurementCase->status === CaseStatus::DRAFT;
        $report = $isDraft ? $readiness->evaluate($procurementCase) : null;

        return Inertia::render('requests/show', [
            'case' => $this->detail($request, $procurementCase),
            'documents' => RequestDocuments::for($procurementCase, $request->user()),
            // Drafts only: the reasons a submit would be refused right now.
            'readiness' => $report ? (new ReadinessResource($report))->resolve($request) : null,
            // UI-3: an AD_HOC request shows the same summary as Review and submit, which needs the option labels.
            'summary_config' => $procurementCase->procurementType->code === ProcurementTypeProfile::AD_HOC
                ? Arr::only(AdHocFormConfig::for(), ['options', 'not_awarded'])
                : null,
            'can' => $this->can($request, [
                'submit' => $isDraft && $report->isReady()
                    && Gate::allows('act', [$procurementCase, self::PERMISSION->value]),
            ]),
        ]);
    }

    /** Printable submission receipt. Drafts have none (404). */
    public function receipt(ProcurementCase $procurementCase): Response
    {
        Gate::authorize('view', $procurementCase);
        abort_if($procurementCase->status === CaseStatus::DRAFT, 404);

        return Inertia::render('requests/receipt', [
            'receipt' => RequestReceipt::for($procurementCase),
        ]);
    }

    public function edit(Request $request, ProcurementCase $procurementCase, SubmissionReadiness $readiness): Response|RedirectResponse
    {
        Gate::authorize('view', $procurementCase);

        if ($procurementCase->status !== CaseStatus::DRAFT) {
            return to_route('requests.show', $procurementCase);
        }

        Gate::authorize('act', [$procurementCase, self::PERMISSION->value]);

        // Slice F2: an AD_HOC draft always gets the single-page form, never the old one.
        if ($procurementCase->procurementType->code === ProcurementTypeProfile::AD_HOC) {
            return $this->adHocEdit($request, $procurementCase, $readiness);
        }

        return Inertia::render('requests/edit', [
            'case' => $this->detail($request, $procurementCase),
            'readiness' => (new ReadinessResource($readiness->evaluate($procurementCase)))->resolve($request),
            'config' => [...FormConfig::for($procurementCase->procurementType), 'uploads' => RequestDocuments::uploadRules()],
            'documents' => RequestDocuments::for($procurementCase, $request->user()),
            'can' => $this->can($request, ['edit' => true]),
        ]);
    }

    public function update(Request $request, ProcurementCase $procurementCase, SaveRequisition $save): RedirectResponse
    {
        Gate::authorize('view', $procurementCase);

        if ($procurementCase->status !== CaseStatus::DRAFT) {
            return $this->notEditable($procurementCase);
        }

        Gate::authorize('act', [$procurementCase, self::PERMISSION->value]);

        // Throws ValidationException (errors keyed by dotted path) on bad input,
        // including any system key such as status, org_id or technical_status.
        $data = RequisitionData::validate($request->except(['_token', '_method']), $procurementCase->procurementType);

        try {
            $saved = $save->handle($procurementCase, $request->user(), $data);
        } catch (DraftNotEditable) {
            return $this->notEditable($procurementCase);
        }

        if ($procurementCase->procurementType->code === ProcurementTypeProfile::AD_HOC) {
            // Autosave: no banner. The page attaches the new row ids by client_key.
            return back()->with('saved', [
                'id_map' => (object) $saved->idMap,
                'row_ids' => (object) $saved->rowIds,
                'saved_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            ]);
        }

        return back()->with('success', 'Draft saved.');
    }

    /**
     * LIST-1: delete the user's own DRAFT (any procurement type) through
     * CaseEraser. Out of scope is 404 (route model binding); a case that is not
     * a DRAFT is 403. A repeat of a delete that already succeeded is answered
     * by missingDraft() below.
     */
    public function destroy(Request $request, ProcurementCase $procurementCase, CaseEraser $eraser): RedirectResponse
    {
        Gate::authorize('view', $procurementCase);

        abort_if($procurementCase->status !== CaseStatus::DRAFT, 403);

        Gate::authorize('act', [$procurementCase, self::PERMISSION->value]);

        try {
            $eraser->eraseDraft($procurementCase, $request->user());
        } catch (DraftAlreadyDeleted) {
            return to_route('requests.index')->with('success', 'Draft already deleted.');
        } catch (DraftNotEditable) {
            abort(403); // submitted while this delete waited for the lock
        }

        return to_route('requests.index')->with('success', 'Draft deleted.');
    }

    /**
     * Route binding found no case for a DELETE. If this user's organisation
     * deleted that draft (a double submit), say so; anything else stays 404, so
     * nothing is learnt about other organisations' cases.
     */
    public static function missingDraft(Request $request): RedirectResponse
    {
        $orgId = $request->user()?->org_id;
        $caseId = (string) $request->route('procurementCase');

        $deletedByOrg = $orgId !== null && strlen($caseId) === 26 && DB::table('audit_logs')
            ->where('action', AuditAction::DraftDeleted->value)
            ->where('case_id', $caseId)
            ->where('actor_org_id', $orgId)
            ->exists();

        abort_unless($deletedByOrg, 404);

        return to_route('requests.index')->with('success', 'Draft already deleted.');
    }

    /**
     * The AD_HOC single-page form (slice F2). Every prop is a closure, so the
     * page's partial reloads (autosave, Review and submit) compute only what they ask for.
     */
    private function adHocEdit(Request $request, ProcurementCase $case, SubmissionReadiness $readiness): Response
    {
        $report = null;
        $evaluate = function () use (&$report, $readiness, $case) {
            return $report ??= $readiness->evaluate($case);
        };

        return Inertia::render('requests/ad-hoc/edit', [
            'case' => fn () => $this->detail($request, $case),
            'sections' => function () use ($case, $evaluate) {
                $report = $evaluate();
                $case->load(['requisition', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries']);

                return AdHocSections::for($case, $report);
            },
            'config' => fn () => [...AdHocFormConfig::for(), 'uploads' => RequestDocuments::uploadRules()],
            'documents' => fn () => RequestDocuments::for($case, $request->user()),
            'saved' => fn () => $request->session()->get('saved'),
            'hardcopy_notice' => RequestReceipt::HARDCOPY_NOTICE,
            'can' => fn () => $this->can($request, [
                'edit' => true,
                'submit' => Gate::allows('act', [$case, self::PERMISSION->value]),
            ]),
        ]);
    }

    private function detail(Request $request, ProcurementCase $case): array
    {
        $case->load(['procurementType', 'requisition', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries']);

        return (new RequestDetailResource($case))->resolve($request);
    }

    private function notEditable(ProcurementCase $case): RedirectResponse
    {
        return to_route('requests.show', $case)->with('error', 'This request has been submitted and can no longer be edited.');
    }

    /**
     * Page-level abilities. A page prop named "can" replaces the shared one, so
     * the shared can.requests flag is repeated here.
     */
    private function can(Request $request, array $abilities): array
    {
        return ['requests' => $request->user()->can(self::PERMISSION->value), ...$abilities];
    }
}
