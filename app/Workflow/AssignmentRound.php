<?php

namespace App\Workflow;

use App\Enums\AuditAction;
use App\Models\CaseAssignment;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;

/**
 * Closing a desk's round before a new one starts: every ACTIVE assignment with
 * the given purpose gets completed_at (read-only history from then on) and one
 * case.assignment_completed audit row carrying a fixed reason code, never free
 * text. Other purposes are never touched. Call it inside the caller's
 * transaction, after the case row is locked.
 *
 *   T11 (RouteReturnedCase, technical)  reason new_review_round
 *   T6  (SendToLegal, legal)            reason new_legal_round
 */
final class AssignmentRound
{
    public const NEW_REVIEW_ROUND = 'new_review_round';

    public const NEW_LEGAL_ROUND = 'new_legal_round';

    /** Returns how many rows were completed. */
    public static function close(ProcurementCase $case, User $actor, string $purpose, string $reason): int
    {
        $rows = CaseAssignment::query()
            ->where('case_id', $case->getKey())
            ->where('purpose', $purpose)
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->orderBy('id')
            ->get();

        $now = CarbonImmutable::now('UTC');
        foreach ($rows as $row) {
            $row->forceFill(['completed_at' => $now])->save();

            Audit::record(
                AuditAction::CaseAssignmentCompleted,
                ['type' => 'case_assignment', 'id' => $row->getKey()],
                $case->getKey(),
                ['role' => $row->role, 'user_id' => $row->user_id !== null ? (int) $row->user_id : null, 'purpose' => $row->purpose, 'reason' => $reason],
                $actor,
            );
        }

        return $rows->count();
    }
}
