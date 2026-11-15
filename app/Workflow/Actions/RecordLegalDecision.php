<?php

namespace App\Workflow\Actions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\CaseAssignment;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Workflow\CaseTransitionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The Legal decision at WITH_LEGAL (slice 10c): two outcomes only.
 *
 * - confirmed      -> T7, WITH_PS (an optional note is the transition reason)
 * - return_to_cmo  -> T8, RETURNED_TO_CMO (the reason, 10 to 500 characters, is the transition reason)
 *
 * "return_to_cmo", never "not_confirmed": TrackStatus::NOT_CONFIRMED means
 * funding not confirmed.
 *
 * Needs legal.confirm (CasePolicy::act) AND an active Legal-desk assignment:
 * act() alone would let a case.view_all holder through. With several Legal
 * officers the first decision is final; later attempts get AlreadyDecided.
 * Assignments are left as they are. Fail closed.
 */
final class RecordLegalDecision
{
    public const CONFIRMED = 'confirmed';

    public const RETURN_TO_CMO = 'return_to_cmo';

    public const DECISIONS = [self::CONFIRMED, self::RETURN_TO_CMO];

    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    public function __construct(private readonly CaseTransitionService $transitions) {}

    public static function label(string $decision): string
    {
        return $decision === self::CONFIRMED ? 'Contract confirmed' : 'Returned to CMO with a query';
    }

    /** @throws ValidationException|AuthorizationException */
    public function handle(ProcurementCase $case, User $actor, string $decision, ?string $reason = null): StepResult
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $case);

        if (! in_array($decision, self::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => 'Choose a decision.']);
        }

        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        if ($decision === self::RETURN_TO_CMO && ($reason === null || mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX)) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of '.self::REASON_MIN.' to '.self::REASON_MAX.' characters.']);
        }
        if ($reason !== null && mb_strlen($reason) > self::REASON_MAX) {
            throw ValidationException::withMessages(['reason' => 'The note can be at most '.self::REASON_MAX.' characters.']);
        }

        return DB::transaction(function () use ($case, $actor, $gate, $decision, $reason) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            $gate->authorize('act', [$locked, Permission::LegalConfirm->value]);

            if (! self::isAssigned($locked, $actor)) {
                throw new AuthorizationException('Only an assigned Legal officer can record the Legal decision.');
            }

            if ($locked->status !== CaseStatus::WITH_LEGAL) {
                return $this->alreadyDecided($locked) ?? throw ValidationException::withMessages([
                    'legal_decision' => 'The Legal decision can be recorded only while the case is With Legal.',
                ]);
            }

            $to = $decision === self::CONFIRMED ? CaseStatus::WITH_PS : CaseStatus::RETURNED_TO_CMO;
            $this->transitions->transition($locked, $to, $actor, $reason);

            Audit::record(
                AuditAction::CaseLegalDecision,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                ['decision' => $decision, 'reason_given' => $reason !== null],
                $actor,
            );

            return StepResult::done($locked->refresh());
        });
    }

    /** An active (not completed, not cancelled) Legal-desk assignment for this user. */
    public static function isAssigned(ProcurementCase $case, User $user): bool
    {
        return CaseAssignment::query()
            ->where('case_id', $case->getKey())
            ->where('purpose', SendToLegal::PURPOSE)
            ->where('user_id', $user->getKey())
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->exists();
    }

    /** The latest decision out of WITH_LEGAL: who, when and which. */
    private function alreadyDecided(ProcurementCase $case): ?StepResult
    {
        $row = CaseTransition::query()->with('actor:id,name')
            ->where('case_id', $case->getKey())
            ->where('from_status', CaseStatus::WITH_LEGAL->value)
            ->whereIn('to_status', [CaseStatus::WITH_PS->value, CaseStatus::RETURNED_TO_CMO->value])
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $decision = $row->to_status === CaseStatus::WITH_PS ? self::CONFIRMED : self::RETURN_TO_CMO;

        return StepResult::already($case, $row->actor?->name, $row->created_at, self::label($decision));
    }
}
