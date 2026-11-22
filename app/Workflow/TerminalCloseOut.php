<?php

namespace App\Workflow;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\QueryStatus;
use App\Models\CaseAssignment;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * TERMINAL-1: what entering WITHDRAWN or REJECTED_FINAL does besides the
 * status. Runs inside CaseTransitionService's transaction, on the locked case,
 * so it commits or rolls back with the transition:
 *
 * - every query that has not ended is CANCELLED (cancel_reason "Case withdrawn"
 *   or "Case rejected"), one query.cancelled audit row each;
 * - every active assignment is cancelled (cancelled_at), one
 *   case.assignment_cancelled audit row each.
 *
 * Nothing is deleted. The audit rows carry ids and statuses only.
 */
final class TerminalCloseOut
{
    public const REASON_WITHDRAWN = 'Case withdrawn';

    public const REASON_REJECTED = 'Case rejected';

    /** The statuses that trigger a close-out. */
    public static function applies(CaseStatus $to): bool
    {
        return $to === CaseStatus::WITHDRAWN || $to === CaseStatus::REJECTED_FINAL;
    }

    public static function run(ProcurementCase $case, CaseStatus $to, User $actor): void
    {
        if (! self::applies($to)) {
            throw new LogicException("No close-out for {$to->value}.");
        }

        $now = CarbonImmutable::now('UTC');
        $reason = $to === CaseStatus::WITHDRAWN ? self::REASON_WITHDRAWN : self::REASON_REJECTED;

        $queries = CaseQuery::withoutGlobalScopes()
            ->where('case_id', $case->getKey())
            ->whereNotIn('status', QueryStatus::endedValues())
            ->lockForUpdate()
            ->get();

        foreach ($queries as $query) {
            $previous = $query->status;

            $query->forceFill([
                'status' => QueryStatus::CANCELLED,
                'cancelled_at' => $now,
                'cancelled_by' => $actor->getKey(),
                'cancel_reason' => $reason,
            ])->save();

            Audit::record(AuditAction::QueryCancelled, ['type' => 'query', 'id' => $query->getKey()], $case->getKey(), [
                'from' => $previous?->value,
                'case_status' => $to->value,
            ], $actor);
        }

        $assignments = CaseAssignment::query()
            ->where('case_id', $case->getKey())
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->lockForUpdate()
            ->get();

        foreach ($assignments as $assignment) {
            $assignment->forceFill(['cancelled_at' => $now])->save();

            Audit::record(AuditAction::CaseAssignmentCancelled, ['type' => 'case_assignment', 'id' => $assignment->getKey()], $case->getKey(), [
                'role' => $assignment->role,
                'purpose' => $assignment->purpose,
                'user_id' => $assignment->user_id,
                'case_status' => $to->value,
            ], $actor);
        }
    }
}
