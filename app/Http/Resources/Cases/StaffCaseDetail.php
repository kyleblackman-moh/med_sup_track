<?php

namespace App\Http\Resources\Cases;

use App\Enums\CaseStatus;
use App\Enums\DocumentVisibility;
use App\Enums\Permission;
use App\Enums\ReturnKind;
use App\Enums\ReviewDesk;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Enums\TrackStatus;
use App\Http\Resources\Requests\RequestDetailResource;
use App\Http\Resources\Requests\RequestSummary;
use App\Models\CaseAssignment;
use App\Models\CaseTransition;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\ProcurementTypeDocument;
use App\Models\User;
use App\Workflow\Actions\MarkReadyForFile;
use App\Workflow\Actions\RecordHardcopy;
use App\Workflow\Actions\RecordLegalDecision;
use App\Workflow\Actions\RecordPsDecision;
use App\Workflow\Actions\RouteReturnedCase;
use App\Workflow\Actions\SendToLegal;
use App\Workflow\EligibleReviewers;
use App\Workflow\TrackCalculator;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Props for the read-only staff case view (slice 9a), built field by field.
 *
 * - Requisition content comes from RequestDetailResource::requisitionContent,
 *   the same server-side figures the vendor sees.
 * - Quotation technical_* fields are left out for everyone (decision B).
 * - Documents: current and CLEAN only; never storage keys, hashes, mime or
 *   scan internals.
 */
final class StaffCaseDetail
{
    public static function for(ProcurementCase $case, User $user): array
    {
        $case->loadMissing([
            'organisation:id,name', 'procurementType',
            'requisition', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries',
        ]);

        $canForward = $case->status === CaseStatus::CMO_INTAKE
            && Gate::forUser($user)->allows('act', [$case, Permission::CaseRouteToReview->value]);
        $reviewForms = self::reviewForms($case, $user);

        // Slice 10c: the same checks PrepareFile, SendToLegal and RecordLegalDecision run.
        $canPrepare = $case->status === CaseStatus::READY_FOR_FILE
            && Gate::forUser($user)->allows('act', [$case, Permission::FilePrepare->value]);
        $canSendToLegal = $case->status === CaseStatus::FILE_PREPARED
            && Gate::forUser($user)->allows('act', [$case, Permission::FilePrepare->value]);
        $canDecide = $case->status === CaseStatus::WITH_LEGAL
            && Gate::forUser($user)->allows('act', [$case, Permission::LegalConfirm->value])
            && RecordLegalDecision::isAssigned($case, $user);
        // Slice 10d: the same check RecordPsDecision runs.
        $canPsDecide = $case->status === CaseStatus::WITH_PS
            && Gate::forUser($user)->allows('act', [$case, Permission::NonObjectionGrant->value]);
        // Slice 10e: the same checks RouteReturnedCase runs.
        $canRoute = RouteReturnedCase::canRoute($case, $user);
        // Slice 10f: the same checks IssueLetter and CloseCase run.
        $canIssueLetter = $case->status === CaseStatus::NON_OBJECTION_GRANTED
            && Gate::forUser($user)->allows('act', [$case, Permission::LetterIssue->value]);
        $canClose = $case->status === CaseStatus::LETTER_ISSUED
            && Gate::forUser($user)->allows('act', [$case, Permission::LetterIssue->value]);

        return [
            'case' => [
                ...StaffCaseSummary::fields($case),
                'item_description' => $case->requisition?->item_description,
                'category' => $case->procurementType?->category?->value, // null for AD_HOC
                // Slice 10c: staff only (the vendor DTOs never carry it).
                'file_no' => $case->file_no,
                ...RequestDetailResource::requisitionContent($case, forStaff: true),
            ],
            // Slice 10c: the prepare-file form's starting value (config cases.file_no_prefix).
            // Slice 10e: the case's existing file number when it has one (a second round), else the config prefix.
            'file_no_default' => $canPrepare ? (filled($case->file_no) ? (string) $case->file_no : (string) config('cases.file_no_prefix', '')) : null,
            // Slice 10c: eligible Legal officers (id and name), only for a user who may send to Legal.
            'legal_officers' => $canSendToLegal ? app(EligibleReviewers::class)->legal() : null,
            'legal_form' => $canDecide ? [
                'decisions' => array_map(fn (string $d) => [
                    'value' => $d,
                    'label' => $d === RecordLegalDecision::CONFIRMED ? 'Contract confirmed' : 'Return to CMO with a query',
                    'needs_reason' => $d === RecordLegalDecision::RETURN_TO_CMO,
                ], RecordLegalDecision::DECISIONS),
            ] : null,
            'returned' => self::returned($case),
            // Slice 10d: the PS decision form (only for a user who may decide now),
            // the latest PS outcome (replaces the form once decided), and the grant line.
            'ps_form' => $canPsDecide ? [
                'decisions' => [
                    ['value' => RecordPsDecision::GRANT, 'label' => 'Grant non-objection', 'needs_reason' => false],
                    ['value' => RecordPsDecision::RETURN_TO_CMO, 'label' => 'Return to CMO with a query', 'needs_reason' => true],
                ],
                'kinds' => array_map(fn (ReturnKind $k) => ['value' => $k->value, 'label' => $k->label()], ReturnKind::cases()),
            ] : null,
            'ps_outcome' => $canPsDecide ? null : self::psOutcome($case),
            'granted' => self::granted($case),
            // Slice 10e: the Send onward panel, only for a user who may route this returned case now.
            'route_panel' => $canRoute ? self::routePanel($case, $user) : null,
            // Slice 10f: the recorded letter (staff only), and the latest date the issue form accepts.
            'letter' => self::letter($case),
            'letter_dates' => $canIssueLetter ? ['today' => CarbonImmutable::now(RecordHardcopy::TIMEZONE)->toDateString()] : null,
            'hardcopy' => self::hardcopy($case),
            // Bounds for the date input; the server re-checks both.
            'hardcopy_dates' => [
                'today' => CarbonImmutable::now(RecordHardcopy::TIMEZONE)->toDateString(),
                'earliest' => $case->submitted_at?->copy()->setTimezone(RecordHardcopy::TIMEZONE)->toDateString(),
            ],
            'documents' => self::documents($case, $user),
            // V2-3: the one RequestSummary (as the vendor sees it, with the staff document rules);
            // null for the old Pharm and Non-Pharm types, which keep RequisitionView.
            // NEXT part 5: without Documents; the case page has its own document list with actions.
            'summary' => RequestSummary::for($case, $user, forStaff: true, withDocuments: false),
            'timeline' => self::timeline($case),
            'assignments' => self::assignments($case),
            'gate' => self::gate($case),
            // Slice 10b: the outcome forms this user may use (empty for everyone else).
            'review_forms' => $reviewForms,
            // Slice 10a: eligible reviewers, only for a user who may send this case for review.
            'reviewers' => $canForward ? [
                ReviewDesk::Technical->field() => app(EligibleReviewers::class)->for(ReviewDesk::Technical),
                ReviewDesk::Funding->field() => app(EligibleReviewers::class)->for(ReviewDesk::Funding),
            ] : null,
            // A page prop named "can" replaces the shared one, so the shared
            // navigation flags are repeated here (as in RequestController).
            'can' => [
                'requests' => $user->can(Permission::CaseSubmit->value),
                'cases' => Gate::forUser($user)->allows('viewStaffCases', ProcurementCase::class),
                'download_documents' => $user->can(Permission::DocumentDownload->value),
                // Slice 9b actions: the same policy checks the services run.
                'intake' => $case->status === CaseStatus::SUBMITTED
                    && Gate::forUser($user)->allows('act', [$case, Permission::CaseIntake->value]),
                'record_hardcopy' => in_array($case->status, RecordHardcopy::STATUSES, true)
                    && Gate::forUser($user)->allows('act', [$case, Permission::HardcopyConfirm->value]),
                // Slice 10a: the same check ForwardForReview runs.
                'forward_for_review' => $canForward,
                // Slice 10b: the same checks RecordReviewOutcome and MarkReadyForFile run.
                'record_review' => $reviewForms !== [],
                'mark_ready_for_file' => $case->status === CaseStatus::IN_REVIEW
                    && Gate::forUser($user)->allows('act', [$case, Permission::FilePrepare->value]),
                'prepare_file' => $canPrepare,
                'send_to_legal' => $canSendToLegal,
                'record_legal_decision' => $canDecide,
                'record_ps_decision' => $canPsDecide,
                'route_onward' => $canRoute,
                'issue_letter' => $canIssueLetter,
                'close_case' => $canClose,
            ],
        ];
    }

    /**
     * The "returned to CMO" banner (slice 10c): null unless the case is
     * RETURNED_TO_CMO. From the latest transition into that status; the source
     * comes from its from_status (CaseStatus::returnedBySource). Staff only.
     */
    private static function returned(ProcurementCase $case): ?array
    {
        if ($case->status !== CaseStatus::RETURNED_TO_CMO) {
            return null;
        }

        $row = CaseTransition::query()->with('actor:id,name')
            ->where('case_id', $case->getKey())
            ->where('to_status', CaseStatus::RETURNED_TO_CMO->value)
            // By id only, like RouteReturnedCase::latestReturn and the T17 guard.
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'source' => $row->from_status?->returnedBySource(),
            // Slice 10d: "legal-related query" / "technical query" on a PS return; null otherwise.
            'kind' => $row->return_kind?->queryLabel(),
            'reason' => $row->reason,
            'by' => $row->actor?->name,
            'at' => $row->created_at?->copy()->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * The Send onward side panel (slice 10e). Only the destinations this user
     * may choose now, the suggested one, a plain sentence for each, the eligible
     * Technocrats (when Technocrats is offered) and the last 8 history rows,
     * newest first. No funding amounts, no quotation data.
     */
    private static function routePanel(ProcurementCase $case, User $user): array
    {
        $destinations = RouteReturnedCase::destinationsFor($case, $user);
        $legalNames = CaseAssignment::query()->with('user:id,name')
            ->where('case_id', $case->getKey())
            ->where('purpose', SendToLegal::PURPOSE)
            ->whereNotNull('user_id')
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->orderBy('id')->get()
            ->map(fn (CaseAssignment $a) => $a->user?->name)->filter()->unique()->values()->all();

        $options = array_map(fn (string $d) => match ($d) {
            RouteReturnedCase::TECHNOCRATS => [
                'value' => $d,
                'label' => 'Technocrats',
                'consequence' => 'The case returns to In review. The technical review restarts with the Technocrats you choose; funding and hardcopy stay as recorded.',
                'available' => true,
            ],
            RouteReturnedCase::LEGAL => [
                'value' => $d,
                'label' => 'Legal',
                'consequence' => $legalNames === []
                    ? RouteReturnedCase::NO_LEGAL_OFFICER.'.'
                    : 'The case goes back to With Legal, to the Legal officer'.(count($legalNames) > 1 ? 's' : '').' already assigned: '.implode(', ', $legalNames).'.',
                'available' => $legalNames !== [],
            ],
            RouteReturnedCase::PS => [
                'value' => $d,
                'label' => 'PS office',
                'consequence' => 'The case goes back to the PS office for a decision.',
                'available' => true,
            ],
        }, $destinations);

        $available = array_values(array_filter($options, fn (array $o) => $o['available']));
        $suggested = RouteReturnedCase::suggested($case);
        if (! in_array($suggested, array_column($available, 'value'), true)) {
            $suggested = $available[0]['value'] ?? null;
        }
        $suggestedLabel = collect($options)->firstWhere('value', $suggested)['label'] ?? null;

        return [
            'destinations' => $options,
            'suggested' => $suggested,
            'suggested_line' => $suggestedLabel !== null ? "Suggested: {$suggestedLabel}" : null,
            'technocrats' => in_array(RouteReturnedCase::TECHNOCRATS, $destinations, true)
                ? app(EligibleReviewers::class)->for(ReviewDesk::Technical)
                : [],
            'reviewers_min' => RouteReturnedCase::REVIEWERS_MIN,
            'reviewers_max' => RouteReturnedCase::REVIEWERS_MAX,
            'note_min' => RouteReturnedCase::NOTE_MIN,
            'note_max' => RouteReturnedCase::NOTE_MAX,
            'timeline' => array_slice(array_reverse(self::timeline($case)), 0, 8),
        ];
    }

    /**
     * "Letter issued: dated ..., reference ..., emailed on ..., hardcopy sent
     * on ..., recorded by ..., on ..." (slice 10f): null unless the case is
     * LETTER_ISSUED or CLOSED and a letter is on record. Staff only.
     */
    private static function letter(ProcurementCase $case): ?array
    {
        if (! in_array($case->status, [CaseStatus::LETTER_ISSUED, CaseStatus::CLOSED], true) || $case->letter_date === null) {
            return null;
        }

        return [
            'date' => $case->letter_date->toDateString(),
            'reference' => $case->letter_reference,
            'emailed_on' => $case->letter_emailed_on?->toDateString(),
            'hardcopy_sent_on' => $case->letter_hardcopy_sent_on?->toDateString(),
            'recorded_by' => User::query()->whereKey($case->letter_recorded_by)->value('name'),
            'recorded_at' => $case->letter_recorded_at?->copy()->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * "Non-objection granted by <name>, <date>" plus the optional note (slice
     * 10d): null unless the case is NON_OBJECTION_GRANTED. Staff only.
     */
    private static function granted(ProcurementCase $case): ?array
    {
        if ($case->status !== CaseStatus::NON_OBJECTION_GRANTED) {
            return null;
        }

        $row = RecordPsDecision::latestRow($case);
        if ($row === null || $row->to_status !== CaseStatus::NON_OBJECTION_GRANTED) {
            return null;
        }

        return [
            'by' => $row->actor?->name,
            'at' => $row->created_at?->copy()->utc()->toIso8601ZuluString(),
            'note' => $row->reason,
        ];
    }

    /**
     * The latest PS decision as a plain statement (outcome, who, when), shown
     * in the Actions tab in place of the form. Null when there is none.
     */
    private static function psOutcome(ProcurementCase $case): ?array
    {
        $row = RecordPsDecision::latestRow($case);

        return $row === null ? null : [
            'outcome' => RecordPsDecision::outcomeLabel($row),
            'by' => $row->actor?->name,
            'at' => $row->created_at?->copy()->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * The gate tracker (slice 10b; was the 10a Review progress panel). Null
     * before the case is sent for review. Three rows: technical and funding with
     * each assigned reviewer's latest outcome (the reason of a negative outcome
     * is included: this DTO is staff-only), and hardcopy. `open` and `missing`
     * come from TransitionRules::gateMissing, the same check the T4 guard runs.
     * No deadlines, no lateness.
     */
    private static function gate(ProcurementCase $case): ?array
    {
        if ($case->technical_track === null && $case->funding_track === null) {
            return null;
        }

        $rows = array_map(function (ReviewDesk $desk) use ($case) {
            $track = $case->{$desk->track()};

            return [
                'key' => $desk->value,
                'label' => $desk->label(),
                'done' => $track === $desk->positiveTrack(),
                'state' => $track instanceof TrackStatus ? $track->label() : 'Not started',
                'reviewers' => TrackCalculator::reviewers($case, $desk)->map(fn (array $r) => [
                    'name' => $r['assignment']->user?->name,
                    'outcome' => $r['latest']?->outcome->label(),
                    'recorded_at' => $r['latest']?->created_at?->copy()->utc()->toIso8601ZuluString(),
                    'reason' => $r['latest'] !== null && ! $r['latest']->outcome->isPositive() ? $r['latest']->reason : null,
                ])->values()->all(),
                'received_on' => null,
                'recorded_by' => null,
                'recorded_at' => null,
            ];
        }, ReviewDesk::cases());

        $hardcopy = self::hardcopy($case);
        $rows[] = [
            'key' => 'hardcopy',
            'label' => 'Hardcopy received',
            'done' => $case->hardcopyReceived(),
            'state' => $case->hardcopyReceived() ? 'Received' : 'Not received',
            'reviewers' => [],
            'received_on' => $hardcopy['received_on'] ?? null,
            'recorded_by' => $hardcopy['recorded_by'] ?? null,
            'recorded_at' => $hardcopy['recorded_at'] ?? null,
        ];

        $inReview = $case->status === CaseStatus::IN_REVIEW;
        $missing = TransitionRules::gateMissing($case);

        return [
            'rows' => $rows,
            'open' => $inReview && $missing === [],
            'missing' => $inReview ? MarkReadyForFile::messages($missing) : [],
        ];
    }

    /**
     * The outcome forms this user may use: one per desk where they hold the
     * permission (CasePolicy::act) AND an active assignment, while IN_REVIEW.
     * The same checks RecordReviewOutcome runs. Includes their current outcome.
     *
     * @return list<array<string, mixed>>
     */
    private static function reviewForms(ProcurementCase $case, User $user): array
    {
        if ($case->status !== CaseStatus::IN_REVIEW) {
            return [];
        }

        $forms = [];
        foreach (ReviewDesk::cases() as $desk) {
            if (! TrackCalculator::canRecordReview($case, $user, $desk)) {
                continue;
            }

            $latest = TrackCalculator::reviewers($case, $desk)[(int) $user->getKey()]['latest'];
            [$positive, $negative] = $desk->outcomes();

            $forms[] = [
                'track' => $desk->value,
                'label' => $desk->label(),
                'outcomes' => [
                    ['value' => $positive->value, 'label' => $positive->label(), 'positive' => true],
                    ['value' => $negative->value, 'label' => $negative->label(), 'positive' => false],
                ],
                'current' => $latest === null ? null : [
                    'outcome' => $latest->outcome->value,
                    'label' => $latest->outcome->label(),
                    'reason' => $latest->reason,
                    'recorded_at' => $latest->created_at?->copy()->utc()->toIso8601ZuluString(),
                ],
            ];
        }

        return $forms;
    }

    /** Received date, who recorded it and when; null when not received. */
    private static function hardcopy(ProcurementCase $case): ?array
    {
        if (! $case->hardcopyReceived()) {
            return null;
        }

        return [
            'received_on' => $case->hardcopy_received_on->toDateString(),
            'recorded_by' => User::query()->whereKey($case->hardcopy_received_by)->value('name'),
            'recorded_at' => $case->hardcopy_received_at?->copy()->utc()->toIso8601ZuluString(),
        ];
    }

    private static function documents(ProcurementCase $case, User $user): array
    {
        $gate = Gate::forUser($user);
        $labels = ProcurementTypeDocument::where('procurement_type_id', $case->procurement_type_id)
            ->get(['purpose', 'label'])
            ->mapWithKeys(fn (ProcurementTypeDocument $d) => [$d->purpose->value => $d->label]);

        return Document::query()->current()
            ->where('case_id', $case->getKey())
            ->where('scan_status', ScanStatus::CLEAN->value) // staff never see unscanned or rejected files
            ->with('uploader:id,name')
            ->orderBy('uploaded_at')->orderBy('id')
            ->get()
            ->filter(fn (Document $d) => $gate->allows('view', $d))
            ->map(fn (Document $d) => [
                'id' => $d->id,
                'display_name' => $d->original_name,
                'purpose_label' => $labels[$d->purpose->value] ?? Str::headline($d->purpose->value),
                'version' => $d->version,
                'uploaded_at' => $d->uploaded_at?->copy()->utc()->toIso8601ZuluString(),
                'uploaded_by' => $d->uploader?->name,
                'visibility' => $d->visibility === DocumentVisibility::INTERNAL ? 'Internal' : 'External',
                'can_download' => $gate->allows('download', $d),
            ])->values()->all();
    }

    private static function timeline(ProcurementCase $case): array
    {
        return $case->transitions()->with(['actor:id,name', 'actingFor:id,name'])->get()
            ->map(fn (CaseTransition $t) => [
                'id' => $t->id,
                'from_label' => $t->from_status?->label(),
                'to_label' => $t->to_status->label(),
                'actor' => $t->actor?->name,
                'acting_for' => $t->actingFor?->name,
                'reason' => $t->reason,
                'at' => $t->created_at?->copy()->utc()->toIso8601ZuluString(),
            ])->values()->all();
    }

    private static function assignments(ProcurementCase $case): array
    {
        return $case->assignments()->with('user:id,name')
            ->orderBy('assigned_at')->orderBy('id')->get()
            ->map(fn (CaseAssignment $a) => [
                'id' => $a->id,
                'role_label' => Role::tryFrom($a->role)?->label() ?? Str::headline($a->role),
                'user' => $a->user?->name, // null: any user in the role (pool)
                'state' => match (true) {
                    $a->cancelled_at !== null => 'Cancelled',
                    $a->completed_at !== null => 'Completed',
                    default => 'Active',
                },
                'assigned_at' => $a->assigned_at?->copy()->utc()->toIso8601ZuluString(),
                'completed_at' => $a->completed_at?->copy()->utc()->toIso8601ZuluString(),
            ])->values()->all();
    }
}
