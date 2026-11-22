<?php

namespace App\Workflow;

use App\Enums\CaseStatus as S;
use App\Enums\Permission as P;
use App\Enums\TrackStatus;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Submissions\SubmissionReadiness;
use App\Workflow\Exceptions\GateNotPassed;
use Illuminate\Support\Facades\DB;

/**
 * The transitions table (spec 6.5), all rules in one list.
 *
 * holderRole values are labels for cases.current_holder_role (spec 6.2), not
 * authorisation checks. Authorisation is by permission, through CasePolicy::act.
 */
final class TransitionRules
{
    /** @var list<TransitionRule>|null */
    private static ?array $rules = null;

    /** @return list<TransitionRule> */
    public static function all(): array
    {
        return self::$rules ??= self::build();
    }

    /** The enabled rule for this pair, or null. Disabled rules do not exist here. */
    public static function find(S $from, S $to): ?TransitionRule
    {
        return self::candidates($from, $to)[0] ?? null;
    }

    /**
     * TERMINAL-1: the rule $actor would use for this pair. Two rules can share a
     * pair (T15V and T15 both leave SUBMITTED for WITHDRAWN): the first whose
     * permission $actor holds, else the first (whose check then refuses them).
     */
    public static function findFor(S $from, S $to, User $actor): ?TransitionRule
    {
        $candidates = self::candidates($from, $to);

        foreach ($candidates as $rule) {
            if ($actor->can($rule->permission)) {
                return $rule;
            }
        }

        return $candidates[0] ?? null;
    }

    /** @return list<TransitionRule> */
    private static function candidates(S $from, S $to): array
    {
        if ($from->isTerminal()) {
            return [];
        }

        return array_values(array_filter(
            self::all(),
            fn (TransitionRule $rule) => $rule->isEnabled() && $rule->to === $to && $rule->appliesFrom($from),
        ));
    }

    /** @return list<TransitionRule> */
    private static function build(): array
    {
        return [
            // Reached only through App\Submissions\SubmitCase, which allocates the reference first.
            new TransitionRule('T1', [S::DRAFT], S::SUBMITTED, P::CaseSubmit->value,
                holderRole: 'cmo_registry', guard: self::submitGuard(...)),

            new TransitionRule('T2', [S::SUBMITTED], S::CMO_INTAKE, P::CaseIntake->value,
                holderRole: 'cmo'),

            // IN_REVIEW has no single holder: the tracks run in parallel.
            new TransitionRule('T3', [S::CMO_INTAKE], S::IN_REVIEW, P::CaseRouteToReview->value,
                holderRole: null, resetTracks: ['technical_track', 'funding_track'],
                pendingGuards: ['assignments_created']),

            // The gate (spec 6.4). Only the CMO path; the automatic system path is a later slice.
            new TransitionRule('T4', [S::IN_REVIEW], S::READY_FOR_FILE, P::FilePrepare->value,
                holderRole: 'cmo', guard: self::gateGuard(...)),

            // Slice 10c: PrepareFile writes file_no in the same transaction, before this runs.
            new TransitionRule('T5', [S::READY_FOR_FILE], S::FILE_PREPARED, P::FilePrepare->value,
                holderRole: 'cmo', guard: self::fileNoGuard(...)),

            // Slice 10c: no code path reaches WITH_LEGAL without a file number.
            new TransitionRule('T6', [S::FILE_PREPARED], S::WITH_LEGAL, P::FilePrepare->value,
                holderRole: 'legal_officer', guard: self::fileNoGuard(...)),

            new TransitionRule('T7', [S::WITH_LEGAL], S::WITH_PS, P::LegalConfirm->value,
                holderRole: 'ps_office', pendingGuards: ['no_open_legal_queries']),

            new TransitionRule('T8', [S::WITH_LEGAL], S::RETURNED_TO_CMO, P::LegalConfirm->value,
                requiresReason: true, holderRole: 'cmo', pendingGuards: ['legal_query_created']),

            new TransitionRule('T9', [S::WITH_PS], S::NON_OBJECTION_GRANTED, P::NonObjectionGrant->value,
                holderRole: 'cmo', pendingGuards: ['no_open_ps_queries']),

            new TransitionRule('T10', [S::WITH_PS], S::RETURNED_TO_CMO, P::NonObjectionGrant->value,
                requiresReason: true, holderRole: 'cmo', pendingGuards: ['ps_query_created']),

            // ASSUMPTION (spec 6.5 T11): only the technical track is reset when the CMO
            // routes a returned case back to review; funding and hardcopy stand.
            new TransitionRule('T11', [S::RETURNED_TO_CMO], S::IN_REVIEW, P::CaseRouteToReview->value,
                requiresReason: true, holderRole: null, resetTracks: ['technical_track']),

            // Slice 10e: the CMO sends a returned case back to Legal with a note (the reason).
            new TransitionRule('T12', [S::RETURNED_TO_CMO], S::WITH_LEGAL, P::QueryForward->value,
                requiresReason: true, holderRole: 'legal_officer'),

            // Slice 10f: the letter is recorded by hand (IssueLetter writes it before T13).
            new TransitionRule('T13', [S::NON_OBJECTION_GRANTED], S::LETTER_ISSUED, P::LetterIssue->value,
                holderRole: 'cmo', guard: self::letterRecordedGuard(...)),

            // Slice 10f: closing reuses letter.issue (no separate permission).
            new TransitionRule('T14', [S::LETTER_ISSUED], S::CLOSED, P::LetterIssue->value,
                holderRole: null, guard: self::letterOnRecordGuard(...)),

            // TERMINAL-1: the vendor withdraws its own request (own org: CasePolicy::act)
            // before intake. A separate permission from the staff rule below.
            new TransitionRule('T15V', [S::DRAFT, S::SUBMITTED], S::WITHDRAWN, P::CaseWithdrawOwn->value,
                requiresReason: true, holderRole: null),

            // ASSUMPTION (TERMINAL-1): spec T15 omits CMO_INTAKE; the CMO may withdraw there too.
            new TransitionRule('T15', [S::SUBMITTED, S::CMO_INTAKE, S::IN_REVIEW], S::WITHDRAWN, P::CaseWithdraw->value,
                requiresReason: true, holderRole: null),

            // ASSUMPTION (TERMINAL-1, Q3 open): the CMO records a final rejection on the
            // PS's instruction. Behind workflow.final_reject_enabled; the service also
            // requires the on-PS-instruction flag. No resubmission: the case is terminal.
            new TransitionRule('T16', [S::WITH_PS, S::RETURNED_TO_CMO], S::REJECTED_FINAL, P::CaseRejectFinal->value,
                requiresReason: true, holderRole: null, enabledBy: 'workflow.final_reject_enabled'),

            // Assumption A1: after a PS query is answered the case returns to the PS.
            // Slice 10e: only a case the PS returned can go back to the PS.
            new TransitionRule('T17', [S::RETURNED_TO_CMO], S::WITH_PS, P::QueryForward->value,
                requiresReason: true, holderRole: 'ps_office', guard: self::returnedByPsGuard(...)),
        ];
    }

    /**
     * T1: the request content and required documents pass SubmissionReadiness,
     * and a reference number has been allocated.
     */
    private static function submitGuard(ProcurementCase $case, User $actor): void
    {
        $missing = [];

        if (blank(DB::table('cases')->where('id', $case->getKey())->value('reference_no'))) {
            $missing[] = 'reference_no';
        }

        foreach (app(SubmissionReadiness::class)->evaluate($case)->errors as $error) {
            $missing[] = $error['key'];
        }

        if ($missing !== []) {
            throw new GateNotPassed($missing);
        }
    }

    /**
     * The T4 gate conditions not yet met: 'technical', 'funding', 'hardcopy'.
     * The one definition of the gate: the guard below and the case view's gate
     * tracker (slice 10b) both use it.
     *
     * @return list<string>
     */
    public static function gateMissing(ProcurementCase $case): array
    {
        $missing = [];

        if ($case->technical_track !== TrackStatus::FAVOURABLE) {
            $missing[] = 'technical';
        }
        if ($case->funding_track !== TrackStatus::CONFIRMED) {
            $missing[] = 'funding';
        }
        if (! $case->hardcopyReceived()) {
            $missing[] = 'hardcopy';
        }

        return $missing;
    }

    /** T4: technical favourable, funding confirmed and hardcopy received. */
    private static function gateGuard(ProcurementCase $case, User $actor): void
    {
        $missing = self::gateMissing($case);

        if ($missing !== []) {
            throw new GateNotPassed($missing);
        }
    }

    /** Plain-English messages for the file-number guard key (shown to staff). */
    public const GUARD_MESSAGES = [
        'file_no' => 'File number has not been entered',
        'returned_by_ps' => 'Only a case returned by the PS can go back to the PS',
        'letter_date' => 'The letter date has not been recorded',
        'letter_channel' => 'Record when the letter was emailed or when the hardcopy was sent',
    ];

    /** T5 and T6 (slice 10c): the case has a file number. Read from the row, not the model. */
    private static function fileNoGuard(ProcurementCase $case, User $actor): void
    {
        if (blank(DB::table('cases')->where('id', $case->getKey())->value('file_no'))) {
            throw new GateNotPassed(['file_no']);
        }
    }

    /**
     * T17 (slice 10e): the latest move into RETURNED_TO_CMO came from WITH_PS.
     * Read from case_transitions, so a direct service call cannot bypass it.
     */
    private static function returnedByPsGuard(ProcurementCase $case, User $actor): void
    {
        $from = DB::table('case_transitions')
            ->where('case_id', $case->getKey())
            ->where('to_status', S::RETURNED_TO_CMO->value)
            ->orderByDesc('id')
            ->value('from_status');

        if ($from !== S::WITH_PS->value) {
            throw new GateNotPassed(['returned_by_ps']);
        }
    }

    /** T13 (slice 10f): the letter date and at least one channel date. Read from the row. */
    private static function letterRecordedGuard(ProcurementCase $case, User $actor): void
    {
        $row = DB::table('cases')->where('id', $case->getKey())
            ->first(['letter_date', 'letter_emailed_on', 'letter_hardcopy_sent_on']);

        $missing = [];
        if ($row?->letter_date === null) {
            $missing[] = 'letter_date';
        }
        if ($row?->letter_emailed_on === null && $row?->letter_hardcopy_sent_on === null) {
            $missing[] = 'letter_channel';
        }

        if ($missing !== []) {
            throw new GateNotPassed($missing);
        }
    }

    /** T14 (slice 10f): a letter is on record. Read from the row. */
    private static function letterOnRecordGuard(ProcurementCase $case, User $actor): void
    {
        if (DB::table('cases')->where('id', $case->getKey())->value('letter_date') === null) {
            throw new GateNotPassed(['letter_date']);
        }
    }
}
