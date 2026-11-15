<?php

namespace App\Workflow\Actions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\ReturnKind;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Workflow\CaseTransitionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The PS decision at WITH_PS (slice 10d). Same structure as RecordLegalDecision.
 *
 * - grant          -> T9, NON_OBJECTION_GRANTED (an optional note is the transition reason)
 * - return_to_cmo  -> T10, RETURNED_TO_CMO with a return_kind (legal | technical)
 *                     on the same history row, and a 10 to 500 character reason
 *
 * Needs nonobjection.grant through CasePolicy::act (the CMO does not hold it).
 * The first decision is final; later attempts get AlreadyDecided. Assignments
 * are left as they are. T16 (REJECTED_FINAL) stays disabled: nothing here
 * can reach it. Fail closed.
 */
final class RecordPsDecision
{
    public const GRANT = 'grant';

    public const RETURN_TO_CMO = 'return_to_cmo';

    public const DECISIONS = [self::GRANT, self::RETURN_TO_CMO];

    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    public function __construct(private readonly CaseTransitionService $transitions) {}

    /** @throws ValidationException */
    public function handle(ProcurementCase $case, User $actor, string $decision, ?string $reason = null, ?string $returnKind = null): StepResult
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $case);

        if (! in_array($decision, self::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => 'Choose a decision.']);
        }

        $kind = null;
        if ($decision === self::RETURN_TO_CMO) {
            $kind = ReturnKind::tryFrom((string) $returnKind)
                ?? throw ValidationException::withMessages(['return_kind' => 'Choose whether the query is legal-related or technical.']);
        } elseif ($returnKind !== null && $returnKind !== '') {
            throw ValidationException::withMessages(['return_kind' => 'A query type applies only when returning the case to the CMO.']);
        }

        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        if ($decision === self::RETURN_TO_CMO && ($reason === null || mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX)) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of '.self::REASON_MIN.' to '.self::REASON_MAX.' characters.']);
        }
        if ($reason !== null && mb_strlen($reason) > self::REASON_MAX) {
            throw ValidationException::withMessages(['reason' => 'The note can be at most '.self::REASON_MAX.' characters.']);
        }

        return DB::transaction(function () use ($case, $actor, $gate, $decision, $reason, $kind) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            $gate->authorize('act', [$locked, Permission::NonObjectionGrant->value]);

            if ($locked->status !== CaseStatus::WITH_PS) {
                return self::latest($locked) ?? throw ValidationException::withMessages([
                    'ps_decision' => 'The PS decision can be recorded only while the case is With PS.',
                ]);
            }

            $to = $decision === self::GRANT ? CaseStatus::NON_OBJECTION_GRANTED : CaseStatus::RETURNED_TO_CMO;
            $this->transitions->transition($locked, $to, $actor, $reason, $kind);

            Audit::record(
                AuditAction::CasePsDecision,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                ['decision' => $decision, 'return_kind' => $kind?->value, 'reason_given' => $reason !== null],
                $actor,
            );

            return StepResult::done($locked->refresh());
        });
    }

    /** "Non-objection granted" or "Returned to CMO (legal-related query)". */
    public static function outcomeLabel(CaseTransition $row): string
    {
        return $row->to_status === CaseStatus::NON_OBJECTION_GRANTED
            ? 'Non-objection granted'
            : 'Returned to CMO'.($row->return_kind ? ' ('.$row->return_kind->queryLabel().')' : '');
    }

    /**
     * The latest PS decision (the latest transition out of WITH_PS) as an
     * AlreadyDecided result, or null if there is none.
     */
    private static function latest(ProcurementCase $case): ?StepResult
    {
        $row = self::latestRow($case);

        return $row ? StepResult::already($case, $row->actor?->name, $row->created_at, self::outcomeLabel($row)) : null;
    }

    public static function latestRow(ProcurementCase $case): ?CaseTransition
    {
        return CaseTransition::query()->with('actor:id,name')
            ->where('case_id', $case->getKey())
            ->where('from_status', CaseStatus::WITH_PS->value)
            ->whereIn('to_status', [CaseStatus::NON_OBJECTION_GRANTED->value, CaseStatus::RETURNED_TO_CMO->value])
            ->orderByDesc('id')
            ->first();
    }
}
