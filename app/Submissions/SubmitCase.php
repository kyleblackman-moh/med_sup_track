<?php

namespace App\Submissions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\CaseAssignment;
use App\Models\CaseRequisition;
use App\Models\CaseTransition;
use App\Models\CaseVariationRow;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Submissions\Exceptions\SubmissionNotReady;
use App\Support\Audit;
use App\Workflow\CaseTransitionService;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A vendor submits a DRAFT (T1, DRAFT -> SUBMITTED), in one transaction:
 * lock the case, re-check readiness, allocate the reference number, transition,
 * stamp submitted_at and put the case in the registry's pool.
 *
 * Any failure (including an audit write) rolls back status, reference, counter,
 * assignment and audit together. A second call on a case already past DRAFT
 * changes nothing and returns AlreadySubmitted.
 */
final class SubmitCase
{
    public function __construct(
        private readonly SubmissionReadiness $readiness,
        private readonly ReferenceNumberAllocator $references,
        private readonly CaseTransitionService $transitions,
    ) {}

    /** @throws SubmissionNotReady */
    public function handle(ProcurementCase $case, User $actor): SubmitResult
    {
        Gate::forUser($actor)->authorize('view', $case);

        return DB::transaction(function () use ($case, $actor) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== CaseStatus::DRAFT) {
                return SubmitResult::alreadySubmitted($locked);
            }

            Gate::forUser($actor)->authorize('act', [$locked, Permission::CaseSubmit->value]);

            // Slice V2-1: data in a section this type hides is discarded, so staff
            // never see stale data. Rolled back with everything else if the submit
            // fails.
            $this->discardHiddenSections($locked, $actor);

            // Never trust the page: evaluate again under the lock.
            $report = $this->readiness->evaluate($locked);
            if (! $report->isReady()) {
                throw new SubmissionNotReady($report);
            }

            $reference = $this->references->allocate();
            $locked->forceFill(['reference_no' => $reference])->save();

            Audit::record(
                AuditAction::CaseReferenceAssigned,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                ['reference_no' => $reference],
                $actor,
            );

            // T1's guard re-checks readiness and the reference.
            $this->transitions->transition($locked, CaseStatus::SUBMITTED, $actor);

            $submittedAt = CaseTransition::query()
                ->where('case_id', $locked->getKey())
                ->where('to_status', CaseStatus::SUBMITTED->value)
                ->orderByDesc('id')
                ->value('created_at');
            $locked->forceFill(['submitted_at' => $submittedAt ?? CarbonImmutable::now('UTC')])->save();

            $this->assignToRegistryPool($locked, $actor);

            return SubmitResult::submitted($locked->refresh());
        });
    }

    /**
     * Slice V2-1: data in a section the type hides is discarded at submit, so
     * staff never see stale data (RequestSections::discardedAtSubmit; V2-5: the
     * variation details unless the previous-request answer is Yes). Until submit
     * it stays in the draft, in case the answer changes back. Audits the counts only.
     */
    private function discardHiddenSections(ProcurementCase $case, User $actor): void
    {
        $code = (string) $case->procurementType()->value('code');
        if (! ProcurementTypeProfile::for($code)->isAdHocForm()) {
            return;
        }

        // Kyle, 2026-11-23: "no stock on hand" follows the batch total, checked again under the lock.
        StockOnHand::sync($case->getKey());

        $counts = [];
        foreach (RequestSections::discardedAtSubmit($code, SubmissionReadiness::previousRequest($case)) as $section) {
            $counts[$section] = match ($section) {
                'variation_details' => CaseVariationRow::withoutGlobalScopes()->where('case_id', $case->getKey())->delete(),
            };
        }

        if (array_sum($counts) > 0) {
            Audit::record(
                AuditAction::CaseDraftRowsDiscarded,
                ['type' => 'case', 'id' => $case->getKey()],
                $case->getKey(),
                ['reason' => 'section_hidden', 'counts' => $counts],
                $actor,
            );
        }

        // Kyle, 2026-11-23: request fields this type hides (the tender and previous price
        // outside Tender Excess) are cleared. The audit names the fields, never their values.
        $requisition = CaseRequisition::withoutGlobalScopes()->where('case_id', $case->getKey())->first();
        $cleared = $requisition === null ? [] : array_values(array_filter(
            RequestSections::hiddenFields($code),
            fn (string $field) => $requisition->getAttribute($field) !== null,
        ));

        if ($cleared !== []) {
            $requisition->forceFill(array_fill_keys($cleared, null))->save();
            Audit::record(
                AuditAction::CaseDraftFieldsDiscarded,
                ['type' => 'case', 'id' => $case->getKey()],
                $case->getKey(),
                ['reason' => 'field_hidden', 'fields' => $cleared],
                $actor,
            );
        }
    }

    /**
     * T1 only: one active pool row for the role that now holds the case
     * (T1's holderRole), so its users can see it through VisibleToUserScope.
     */
    private function assignToRegistryPool(ProcurementCase $case, User $actor): void
    {
        $role = TransitionRules::find(CaseStatus::DRAFT, CaseStatus::SUBMITTED)?->holderRole;
        if ($role === null) {
            return;
        }

        $exists = CaseAssignment::query()
            ->where('case_id', $case->getKey())
            ->where('role', $role)
            ->whereNull('user_id')
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->exists();
        if ($exists) {
            return;
        }

        $assignment = (new CaseAssignment)->forceFill([
            'case_id' => $case->getKey(),
            'role' => $role,
            'purpose' => 'intake',
            'user_id' => null,
            'assigned_at' => CarbonImmutable::now('UTC'),
        ]);
        $assignment->save();

        Audit::record(
            AuditAction::CaseAssignedToPool,
            ['type' => 'case_assignment', 'id' => $assignment->getKey()],
            $case->getKey(),
            ['role' => $role, 'purpose' => 'intake'],
            $actor,
        );
    }
}
