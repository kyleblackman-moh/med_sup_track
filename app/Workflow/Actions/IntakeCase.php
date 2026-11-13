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
use App\Workflow\Exceptions\InvalidTransition;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * CMO intake (T2, SUBMITTED -> CMO_INTAKE), in one transaction: lock the case,
 * transition, complete the registry pool row and give the accepting user their
 * own active assignment. Any failure, including an audit write, rolls back all
 * of it (fail closed).
 *
 * Visibility consequence (deliberate): the pool row is completed, so OTHER
 * cmo_registry users stop seeing the case (completed pool rows grant nothing,
 * see VisibleToUserScope). The accepting user keeps it through their own row;
 * cmo and ps_office always see it through case.view_all.
 *
 * T2 only. CaseTransitionService itself never creates assignments.
 */
final class IntakeCase
{
    public function __construct(private readonly CaseTransitionService $transitions) {}

    public function handle(ProcurementCase $case, User $actor): IntakeResult
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $case);

        return DB::transaction(function () use ($case, $actor, $gate) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            // Permission, visibility and eligibility before anything is revealed.
            $gate->authorize('act', [$locked, Permission::CaseIntake->value]);

            if ($locked->status !== CaseStatus::SUBMITTED) {
                return $this->alreadyAccepted($locked)
                    ?? throw new InvalidTransition($locked->status instanceof CaseStatus ? $locked->status : null, CaseStatus::CMO_INTAKE);
            }

            $this->transitions->transition($locked, CaseStatus::CMO_INTAKE, $actor);

            $desk = $this->completePoolRows($locked, $actor)
                ?? TransitionRules::find(CaseStatus::DRAFT, CaseStatus::SUBMITTED)?->holderRole
                ?? 'cmo_registry';

            $this->assignActor($locked, $actor, $desk);

            return IntakeResult::accepted($locked->refresh());
        });
    }

    /** The case was accepted before: who did it and when, from the T2 history row. */
    private function alreadyAccepted(ProcurementCase $case): ?IntakeResult
    {
        $row = CaseTransition::query()
            ->with('actor:id,name')
            ->where('case_id', $case->getKey())
            ->where('from_status', CaseStatus::SUBMITTED->value)
            ->where('to_status', CaseStatus::CMO_INTAKE->value)
            ->orderBy('id')
            ->first();

        return $row ? IntakeResult::alreadyAccepted($case, $row->actor?->name, $row->created_at) : null;
    }

    /**
     * Completes (never deletes) the active pool rows on the case and returns the
     * role of the one replaced: the desk, not the accepting user's own roles.
     */
    private function completePoolRows(ProcurementCase $case, User $actor): ?string
    {
        $now = CarbonImmutable::now('UTC');
        $rows = CaseAssignment::query()
            ->where('case_id', $case->getKey())
            ->whereNull('user_id')
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $row->forceFill(['completed_at' => $now])->save();

            Audit::record(
                AuditAction::CaseAssignmentCompleted,
                ['type' => 'case_assignment', 'id' => $row->getKey()],
                $case->getKey(),
                ['role' => $row->role, 'pool' => true, 'reason' => 'intake'],
                $actor,
            );
        }

        return $rows->first()?->role;
    }

    private function assignActor(ProcurementCase $case, User $actor, string $desk): void
    {
        $assignment = (new CaseAssignment)->forceFill([
            'case_id' => $case->getKey(),
            'role' => $desk,
            'purpose' => 'intake',
            'user_id' => $actor->getKey(),
            'assigned_by' => $actor->getKey(),
            'assigned_at' => CarbonImmutable::now('UTC'),
        ]);
        $assignment->save();

        Audit::record(
            AuditAction::CaseAssignmentCreated,
            ['type' => 'case_assignment', 'id' => $assignment->getKey()],
            $case->getKey(),
            ['role' => $desk, 'user_id' => $actor->getKey(), 'purpose' => 'intake'],
            $actor,
        );
    }
}
