<?php

namespace App\Workflow\Actions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Models\CaseOutcome;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Workflow\CaseTransitionService;
use App\Workflow\Exceptions\InvalidTransition;
use App\Workflow\Exceptions\StaleTransition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * FR-09: end a case early. Withdraw (T15: DRAFT, SUBMITTED or IN_REVIEW ->
 * WITHDRAWN; vendors before intake only) or finally reject (T16: WITH_PS or
 * RETURNED_TO_CMO -> REJECTED_FINAL; the CMO, on the Permanent Secretary's
 * instruction). Both are terminal and cannot be undone.
 *
 * In one transaction: lock, an already-ended case gets a friendly result,
 * the transition (the internal reason goes to case_transitions.reason), the
 * case_outcomes row (the optional message the vendor sees), and an audit row
 * with flags and lengths only, never the text.
 */
final class EndCase
{
    public const REASON_MIN = 10;

    public const REASON_MAX = 1000;

    public function __construct(private readonly CaseTransitionService $transitions) {}

    /** @throws ValidationException */
    public function withdraw(ProcurementCase $case, User $actor, string $reason, ?string $vendorMessage = null): StepResult
    {
        // A vendor's withdrawal is its own words to the Ministry: there is no separate vendor message.
        return $this->end($case, $actor, CaseStatus::WITHDRAWN, $reason, $actor->isVendor() ? null : $vendorMessage, false);
    }

    /** @throws ValidationException */
    public function reject(ProcurementCase $case, User $actor, string $reason, ?string $vendorMessage, bool $psInstructed): StepResult
    {
        if (! $psInstructed) {
            throw ValidationException::withMessages([
                'ps_instructed' => 'Confirm that the Permanent Secretary instructed this rejection.',
            ]);
        }

        return $this->end($case, $actor, CaseStatus::REJECTED_FINAL, $reason, $vendorMessage, true);
    }

    private function end(ProcurementCase $case, User $actor, CaseStatus $to, string $reason, ?string $vendorMessage, bool $psInstructed): StepResult
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);
        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            throw ValidationException::withMessages([
                'reason' => 'Give a reason of '.self::REASON_MIN.' to '.self::REASON_MAX.' characters.',
            ]);
        }
        $vendorMessage = $vendorMessage === null || trim($vendorMessage) === '' ? null : trim($vendorMessage);
        if ($vendorMessage !== null && mb_strlen($vendorMessage) > CaseOutcome::MESSAGE_MAX) {
            throw ValidationException::withMessages([
                'vendor_message' => 'The message to the vendor can be at most '.CaseOutcome::MESSAGE_MAX.' characters.',
            ]);
        }

        Gate::forUser($actor)->authorize('view', $case);

        return DB::transaction(function () use ($case, $actor, $to, $reason, $vendorMessage, $psInstructed) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            // A double click, or another user first: say who, instead of a 403 from the terminal status.
            if ($locked->status === $to && ($already = self::alreadyEnded($locked, $to)) !== null) {
                return $already;
            }

            // Rule, permission, guard (vendors before intake) and the reason: CaseTransitionService.
            try {
                $this->transitions->transition($locked, $to, $actor, $reason);
            } catch (InvalidTransition|StaleTransition) {
                // A status the rule does not cover (or one that just changed): a message, not a 500.
                throw ValidationException::withMessages([
                    'status' => $to === CaseStatus::WITHDRAWN
                        ? 'This case can no longer be withdrawn at its current stage.'
                        : 'This case can be rejected only once it is with the Permanent Secretary or returned from the PS office.',
                ]);
            }

            (new CaseOutcome)->forceFill([
                'case_id' => $locked->getKey(),
                'kind' => $to === CaseStatus::WITHDRAWN ? CaseOutcome::WITHDRAWN : CaseOutcome::REJECTED,
                'vendor_message' => $vendorMessage,
                'ps_instructed' => $psInstructed,
                'actor_id' => $actor->getKey(),
                'created_at' => CarbonImmutable::now('UTC'),
            ])->save();

            Audit::record(
                $to === CaseStatus::WITHDRAWN ? AuditAction::CaseWithdrawn : AuditAction::CaseRejected,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                [
                    'by_vendor' => $actor->isVendor(),
                    'has_vendor_message' => $vendorMessage !== null,
                    'vendor_message_length' => $vendorMessage === null ? 0 : mb_strlen($vendorMessage),
                    'ps_instructed' => $psInstructed,
                ],
                $actor,
            );

            return StepResult::done($locked->refresh());
        });
    }

    private static function alreadyEnded(ProcurementCase $case, CaseStatus $to): ?StepResult
    {
        $row = CaseTransition::query()->with('actor:id,name')
            ->where('case_id', $case->getKey())
            ->where('to_status', $to->value)
            ->orderByDesc('id')
            ->first();

        return $row ? StepResult::already($case, $row->actor?->name, $row->created_at) : null;
    }
}
