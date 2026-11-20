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
use App\Http\Resources\Requests\RequestFormView;
use App\Http\Resources\Requests\RequestProgress;
use App\Http\Resources\Requests\RequestReceipt;
use App\Http\Resources\Requests\RequestReview;
use App\Http\Resources\Requests\RequestSummary;
use App\Http\Resources\Requests\RequestSummaryResource;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use App\Queries\QueryView;
use App\Submissions\CaseEraser;
use App\Submissions\ChangeRequestType;
use App\Submissions\CreateDraftCase;
use App\Submissions\Exceptions\DraftAlreadyDeleted;
use App\Submissions\Exceptions\DraftNotEditable;
use App\Submissions\ExpiryRisk;
use App\Submissions\FormWording;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequisitionData;
use App\Submissions\SaveRequisition;
use App\Submissions\SubmissionReadiness;
use App\Support\RequestGroups;
use App\Support\SqlLike;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

    /** UI-3: the Requests list title. The organisation label is short_name, else name (listTitle). */
    public const LIST_TITLE = '{organisation} - Requests';

    /** UI-3: the longest search the list accepts; longer input is cut to this. */
    public const SEARCH_MAX = 100;

    public function index(Request $request): Response
    {
        $search = mb_substr(trim((string) $request->query('q', '')), 0, self::SEARCH_MAX);
        // UI-4: a count card's filter; anything unknown is ignored.
        $group = (string) $request->query('group', '');
        $group = array_key_exists($group, RequestGroups::LABELS) ? $group : '';

        // The global scope keeps a vendor to its own organisation, search or not.
        $page = ProcurementCase::query()
            ->with(['procurementType', 'requisition'])
            ->when($search !== '', fn ($query) => self::matching($query, $search))
            ->when($group !== '', fn ($query) => RequestGroups::apply($query, $group))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('requests/index', [
            'title' => self::listTitle($request->user()->organisation),
            'filters' => ['q' => $search, 'group' => $group],
            // UI-4: the count cards. One grouped query for the user's own organisation;
            // the search and the card filter never change them. Lazy, so a partial
            // reload that does not ask for them (the live search) skips the query.
            'groups' => function () use ($request) {
                $counts = RequestGroups::counts(ProcurementCase::query()->where('cases.org_id', $request->user()->org_id));

                return array_map(
                    fn (string $key) => ['key' => $key, 'label' => RequestGroups::LABELS[$key], 'count' => $counts[$key]],
                    array_keys(RequestGroups::LABELS),
                );
            },
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

    /** "{organisation label} - requests": the organisation's short name if set, otherwise its name. */
    public static function listTitle(?Organisation $organisation): string
    {
        $label = trim((string) ($organisation?->short_name ?? '')) !== '' ? $organisation->short_name : ($organisation?->name ?? 'Your organisation');

        return str_replace('{organisation}', $label, self::LIST_TITLE);
    }

    /**
     * Partial, case-insensitive match (the database collation is CI) on the
     * reference, item description, strength, DIN and type label. LIKE
     * wildcards in the search are literal: %, _ and [ are escaped with \.
     */
    private static function matching(Builder $query, string $search): Builder
    {
        $like = fn (string $column) => SqlLike::contains($column, $search);

        return $query->where(function (Builder $any) use ($like) {
            $any->whereRaw(...$like('cases.reference_no'))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('case_requisitions')
                    ->whereColumn('case_requisitions.case_id', 'cases.id')
                    ->where(fn ($fields) => $fields->whereRaw(...$like('case_requisitions.item_description'))
                        ->orWhereRaw(...$like('case_requisitions.strength'))
                        ->orWhereRaw(...$like('case_requisitions.din'))))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('procurement_types')
                    ->whereColumn('procurement_types.id', 'cases.procurement_type_id')
                    ->whereRaw(...$like('procurement_types.name')));
        });
    }

    /**
     * POST /requests (requests.store). Slice V2-1b: New request starts a draft
     * at once, with no type chosen yet (NOT_SELECTED); the vendor chooses it in
     * the form. Nothing in the request body is read: the organisation comes
     * from the user. There is no create page any more.
     */
    public function store(Request $request, CreateDraftCase $createDraft): RedirectResponse
    {
        $case = $createDraft->handle($request->user());

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
            // V2-3: a request-form case shows the one RequestSummary (as Review and the staff
            // case page); null for the old Pharm and Non-Pharm types (RequisitionView).
            // NEXT part 5: without Documents; this page has its own document list with actions.
            'summary' => RequestSummary::for($procurementCase, $request->user(), withDocuments: false),
            // OVERNIGHT-2 (B1): dated vendor-facing milestones; empty for a draft.
            'progress' => RequestProgress::for($procurementCase),
            // OVERNIGHT-2 (D3): external, delivered queries addressed to this organisation only.
            'queries' => $isDraft ? [] : QueryView::forVendor($procurementCase, $request->user()),
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
        if (ProcurementTypeProfile::for($procurementCase->procurementType)->isAdHocForm()) {
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

    /**
     * OVERNIGHT-3 (A1): GET /requests/{case}/review, the vendor's Review page for
     * their own organisation's DRAFT. Another organisation's case, and any DRAFT
     * for staff, is 404 (the global scope on route model binding). A case that
     * is no longer a DRAFT goes to its request page; a draft of an old type
     * (no request form, so no summary) goes back to its form, which keeps its
     * own submit panel. Submit Request posts to the existing submit route.
     */
    public function review(Request $request, ProcurementCase $procurementCase, SubmissionReadiness $readiness): Response|RedirectResponse
    {
        Gate::authorize('view', $procurementCase);

        if ($procurementCase->status !== CaseStatus::DRAFT) {
            return to_route('requests.show', $procurementCase);
        }

        Gate::authorize('act', [$procurementCase, self::PERMISSION->value]);

        if (! ProcurementTypeProfile::for($procurementCase->procurementType)->isAdHocForm()) {
            return to_route('requests.edit', $procurementCase);
        }

        $report = $readiness->evaluate($procurementCase);
        $ready = $report->isReady();
        $procurementCase->load(['procurementType', 'requisition', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries', 'variationRows']);

        return Inertia::render('requests/review', [
            'case' => $this->detail($request, $procurementCase),
            // The same read-only summary the form's review showed (Documents included).
            'summary' => RequestSummary::for($procurementCase, $request->user()),
            // What still blocks a submit, each line linking back to its section on the form.
            'blockers' => $ready ? [] : RequestReview::blockers($procurementCase, $report),
            'issue_heading' => RequestFormView::issueHeading($procurementCase, $report),
            'description' => RequestReview::READY_TEXT,
            'not_ready_reason' => $ready ? null : RequestReview::NOT_READY_REASON,
            'hardcopy_notice' => RequestReceipt::HARDCOPY_NOTICE,
            'can' => $this->can($request, [
                'submit' => $ready && Gate::allows('act', [$procurementCase, self::PERMISSION->value]),
            ]),
        ]);
    }

    public function update(Request $request, ProcurementCase $procurementCase, SaveRequisition $save): RedirectResponse
    {
        Gate::authorize('view', $procurementCase);

        if ($procurementCase->status !== CaseStatus::DRAFT) {
            return $this->notEditable($procurementCase);
        }

        Gate::authorize('act', [$procurementCase, self::PERMISSION->value]);

        $payload = $request->except(['_token', '_method']);
        $adHocForm = ProcurementTypeProfile::for($procurementCase->procurementType)->isAdHocForm();

        // FORM-SAVE-1: the request form's draft save (autosave and Save Draft) keeps every
        // valid field and leaves out only what failed (RequisitionData::validateDraft). The
        // older form stays strict: any error rejects the save (errors keyed by dotted path,
        // including any system key such as status, org_id or technical_status).
        [$data, $dropped] = $adHocForm
            ? RequisitionData::validateDraft($payload, $procurementCase->procurementType)
            : [RequisitionData::validate($payload, $procurementCase->procurementType), []];

        try {
            $saved = $save->handle($procurementCase, $request->user(), $data, lenient: $adHocForm, alreadyDropped: count($dropped));
        } catch (DraftNotEditable) {
            return $this->notEditable($procurementCase);
        }

        if ($adHocForm) {
            $notSaved = FormWording::notSaved([...$dropped, ...$saved->dropped]);

            // Autosave: no banner. The page attaches the new row ids by client_key. What was
            // left out comes back here, not as validation errors: the save itself succeeded.
            return back()->with('saved', [
                'id_map' => (object) $saved->idMap,
                'row_ids' => (object) $saved->rowIds,
                'saved_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
                // Slice V2-1 (ExpiryRisk): advisory only.
                'days_on_hand' => $saved->daysOnHand,
                'expiry_warnings' => ExpiryRisk::asList($saved->expiryWarnings),
                // FORM-SAVE-1 (A2): the fields this save left out, and the status line saying so.
                'not_saved' => $notSaved,
                'status' => $notSaved === [] ? null : FormWording::partialSave(count($notSaved)),
                'heading' => $notSaved === [] ? null : FormWording::issueHeading(count($notSaved)),
            ]);
        }

        return back()->with('success', 'Draft saved.');
    }

    /**
     * Slice V2-1: change the user's own DRAFT to another of the five request
     * types (ChangeRequestType). Out of scope is 404 (route model binding); a
     * case that is not a DRAFT is 403; an unknown or inactive type is a
     * validation error on "procurement_type".
     */
    public function changeType(Request $request, ProcurementCase $procurementCase, ChangeRequestType $change): RedirectResponse
    {
        Gate::authorize('view', $procurementCase);

        abort_if($procurementCase->status !== CaseStatus::DRAFT, 403);

        Gate::authorize('act', [$procurementCase, self::PERMISSION->value]);

        $validated = $request->validate(['procurement_type' => ['required', 'string', 'max:40']]);

        try {
            $change->handle($procurementCase, $request->user(), $validated['procurement_type']);
        } catch (DraftNotEditable) {
            abort(403); // submitted while this change waited for the lock
        }

        return to_route('requests.edit', $procurementCase);
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
                $case->load(['requisition', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries', 'variationRows']);

                return AdHocSections::for($case, $report);
            },
            // Slice V2-1: the sections this type shows, in order, with their issues (the V2-2 screen).
            'request_sections' => function () use ($case, $evaluate) {
                $report = $evaluate();
                $case->load(['procurementType', 'requisition', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries', 'variationRows']);

                return AdHocSections::requestSections($case, $report);
            },
            // Slice V2-2: numbered sections with states and required flags, display strings, the expiry warning.
            'form' => function () use ($case, $evaluate) {
                $report = $evaluate();
                $case->load(['procurementType', 'requisition', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries', 'variationRows']);

                return RequestFormView::for($case, $report);
            },
            // V2-3: the read-only summary for Review and submit; sent only when Review asks for it.
            'summary' => Inertia::optional(fn () => RequestSummary::for($case, $request->user())),
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
        $case->load(['procurementType', 'requisition', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries', 'variationRows']);

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
