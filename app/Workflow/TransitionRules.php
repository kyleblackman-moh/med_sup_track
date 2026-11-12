<?php

namespace App\Workflow;

use App\Enums\CaseStatus as S;
use App\Enums\Permission as P;
use App\Enums\TrackStatus;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Submissions\SubmissionReadiness;
use App\Workflow\Exceptions\GateNotPassed;
use Illuminate\Auth\Access\AuthorizationException;
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
        foreach (self::all() as $rule) {
            if ($rule->enabled && $rule->to === $to && $rule->appliesFrom($from)) {
                return $rule;
            }
        }

        return null;
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

            new TransitionRule('T5', [S::READY_FOR_FILE], S::FILE_PREPARED, P::FilePrepare->value,
                holderRole: 'cmo', pendingGuards: ['file_number_issued']),

            new TransitionRule('T6', [S::FILE_PREPARED], S::WITH_LEGAL, P::FilePrepare->value,
                holderRole: 'legal_officer'),

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

            new TransitionRule('T12', [S::RETURNED_TO_CMO], S::WITH_LEGAL, P::QueryForward->value,
                holderRole: 'legal_officer', pendingGuards: ['query_answered_and_forwarded']),

            new TransitionRule('T13', [S::NON_OBJECTION_GRANTED], S::LETTER_ISSUED, P::LetterIssue->value,
                holderRole: 'cmo', pendingGuards: ['letter_generated']),

            new TransitionRule('T14', [S::LETTER_ISSUED], S::CLOSED, P::LetterIssue->value,
                holderRole: null, pendingGuards: ['dispatch_confirmed']),

            new TransitionRule('T15', [S::DRAFT, S::SUBMITTED, S::IN_REVIEW], S::WITHDRAWN, P::CaseWithdraw->value,
                requiresReason: true, holderRole: null, guard: self::withdrawGuard(...)),

            // Rule TBC (open question Q3). Disabled: the service treats it as nonexistent.
            new TransitionRule('T16', [S::WITH_PS, S::RETURNED_TO_CMO], S::REJECTED_FINAL, P::CaseRejectFinal->value,
                requiresReason: true, holderRole: null, enabled: false),

            // Assumption A1: after a PS query is answered the case returns to the PS.
            new TransitionRule('T17', [S::RETURNED_TO_CMO], S::WITH_PS, P::QueryForward->value,
                holderRole: 'ps_office', pendingGuards: ['query_answered_and_forwarded']),
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

    /** T4: technical favourable, funding confirmed and hardcopy received. */
    private static function gateGuard(ProcurementCase $case, User $actor): void
    {
        $missing = [];

        if ($case->technical_track !== TrackStatus::FAVOURABLE) {
            $missing[] = 'technical';
        }
        if ($case->funding_track !== TrackStatus::CONFIRMED) {
            $missing[] = 'funding';
        }
        if ($case->hardcopy_received_at === null) {
            $missing[] = 'hardcopy';
        }

        if ($missing !== []) {
            throw new GateNotPassed($missing);
        }
    }

    /** T15: vendor users may withdraw only before intake; staff from all three statuses. */
    private static function withdrawGuard(ProcurementCase $case, User $actor): void
    {
        if ($actor->isVendor() && ! in_array($case->status, [S::DRAFT, S::SUBMITTED], true)) {
            throw new AuthorizationException('Vendor users can withdraw only before intake.');
        }
    }
}
