<?php

namespace App\Workflow\Actions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Workflow\CaseTransitionService;
use App\Workflow\Exceptions\GateNotPassed;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * T14, LETTER_ISSUED -> CLOSED (slice 10f). Authorised by letter.issue (no
 * separate close permission) through CasePolicy::act. In one transaction:
 * lock, status re-check (an already-closed case gets a friendly result), set
 * cases.closed_at, transition (the T14 guard needs a letter on record), audit.
 * Assignments are left as they are. CLOSED is terminal, so act() refuses every
 * later action: the case is read-only. Fail closed.
 */
final class CloseCase
{
    public function __construct(private readonly CaseTransitionService $transitions) {}

    /** @throws ValidationException */
    public function handle(ProcurementCase $case, User $actor): StepResult
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $case);

        return DB::transaction(function () use ($case, $actor, $gate) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            // A closed case is terminal, so act() would refuse it: a user who holds
            // the permission is told who closed it instead. Everyone else gets 403 below.
            if ($locked->status === CaseStatus::CLOSED && $actor->can(Permission::LetterIssue->value)
                && ($already = self::alreadyClosed($locked)) !== null) {
                return $already;
            }

            $gate->authorize('act', [$locked, Permission::LetterIssue->value]);

            if ($locked->status !== CaseStatus::LETTER_ISSUED) {
                throw ValidationException::withMessages([
                    'close_case' => 'The case can be closed only once the letter is recorded as issued.',
                ]);
            }

            $locked->forceFill(['closed_at' => CarbonImmutable::now('UTC')])->save();

            try {
                $this->transitions->transition($locked, CaseStatus::CLOSED, $actor);
            } catch (GateNotPassed $e) {
                throw ValidationException::withMessages([
                    'close_case' => implode('; ', array_map(fn (string $k) => TransitionRules::GUARD_MESSAGES[$k] ?? $k, $e->missing)).'.',
                ]);
            }

            Audit::record(
                AuditAction::CaseClosed,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                ['from' => CaseStatus::LETTER_ISSUED->value],
                $actor,
            );

            return StepResult::done($locked->refresh());
        });
    }

    private static function alreadyClosed(ProcurementCase $case): ?StepResult
    {
        $row = CaseTransition::query()->with('actor:id,name')
            ->where('case_id', $case->getKey())
            ->where('to_status', CaseStatus::CLOSED->value)
            ->orderByDesc('id')
            ->first();

        return $row ? StepResult::already($case, $row->actor?->name, $row->created_at) : null;
    }
}
