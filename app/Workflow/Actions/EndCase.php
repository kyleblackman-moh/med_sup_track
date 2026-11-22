<?php

namespace App\Workflow\Actions;

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\CaseTransitionService;
use App\Workflow\Exceptions\InvalidTransition;
use App\Workflow\Exceptions\PsInstructionRequired;
use App\Workflow\Exceptions\ReasonRequired;
use App\Workflow\Exceptions\StaleTransition;
use App\Workflow\TransitionRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * TERMINAL-1: withdraw (T15 staff, T15V vendor) and final reject (T16).
 *
 * Thin over CaseTransitionService, which authorises (CasePolicy::act), writes
 * the reason, the message to the vendor and the PS-instruction flag on the
 * history row, and cancels open queries and active assignments in the same
 * transaction. Here: a repeat of a step that already happened is answered
 * with who did it; workflow refusals become form errors. Both end states are
 * terminal (no transition out, no resubmission).
 */
final class EndCase
{
    public const REASON_MAX = 2000;

    public const MESSAGE_MAX = 2000;

    /** Shown to the vendor when the Ministry left no message. */
    public const NO_DETAILS = 'No further details provided.';

    public function __construct(private readonly CaseTransitionService $transitions) {}

    /** @throws ValidationException|AuthorizationException */
    public function withdraw(ProcurementCase $case, User $actor, ?string $reason, ?string $message = null): StepResult
    {
        return $this->end($case, $actor, CaseStatus::WITHDRAWN, 'withdraw', $reason, $message, null);
    }

    /** @throws ValidationException|AuthorizationException */
    public function rejectFinal(ProcurementCase $case, User $actor, ?string $reason, ?string $message, bool $onPsInstruction): StepResult
    {
        // The flag off: T16 does not exist, for the UI and here alike.
        if (! self::finalRejectEnabled()) {
            throw new AuthorizationException('Final rejection is switched off.');
        }

        return $this->end($case, $actor, CaseStatus::REJECTED_FINAL, 'reject_final', $reason, $message, $onPsInstruction);
    }

    public static function finalRejectEnabled(): bool
    {
        return (bool) config('workflow.final_reject_enabled');
    }

    /** May $user withdraw $case now (the vendor rule or the staff rule)? For show/hide only. */
    public static function canWithdraw(User $user, ProcurementCase $case): bool
    {
        return self::canReach($user, $case, CaseStatus::WITHDRAWN);
    }

    /** May $user record a final rejection on $case now? For show/hide only. */
    public static function canRejectFinal(User $user, ProcurementCase $case): bool
    {
        return self::finalRejectEnabled() && self::canReach($user, $case, CaseStatus::REJECTED_FINAL);
    }

    /**
     * TERMINAL-1: what the vendor is told about a withdrawn or rejected case:
     * the label and the message to the vendor, never the internal reason. Null
     * for any other status.
     *
     * @return array{label: string, message: string}|null
     */
    public static function vendorOutcome(ProcurementCase $case): ?array
    {
        if ($case->status !== CaseStatus::WITHDRAWN && $case->status !== CaseStatus::REJECTED_FINAL) {
            return null;
        }

        $message = CaseTransition::query()
            ->where('case_id', $case->getKey())
            ->where('to_status', $case->status->value)
            ->orderByDesc('id')
            ->value('external_message');

        return [
            'label' => $case->status->vendorLabel(),
            'message' => filled($message) ? (string) $message : self::NO_DETAILS,
        ];
    }

    private static function canReach(User $user, ProcurementCase $case, CaseStatus $to): bool
    {
        if (! $case->status instanceof CaseStatus) {
            return false;
        }

        $rule = TransitionRules::findFor($case->status, $to, $user);

        return $rule !== null && Gate::forUser($user)->allows('act', [$case, $rule->permission]);
    }

    private function end(ProcurementCase $case, User $actor, CaseStatus $to, string $field, ?string $reason, ?string $message, ?bool $onPsInstruction): StepResult
    {
        Gate::forUser($actor)->authorize('view', $case);

        // A repeat (double click, or another user got there first) is told who did it,
        // but only by someone who could have done it.
        $permission = $to === CaseStatus::WITHDRAWN
            ? ($actor->isVendor() ? Permission::CaseWithdrawOwn : Permission::CaseWithdraw)
            : Permission::CaseRejectFinal;
        if ($case->status === $to && $actor->can($permission->value)) {
            $row = CaseTransition::query()->with('actor:id,name')
                ->where('case_id', $case->getKey())->where('to_status', $to->value)
                ->orderByDesc('id')->first();

            return StepResult::already($case, $actor->isVendor() && $row?->actor?->isVendor() !== true ? null : $row?->actor?->name, $row?->created_at);
        }

        try {
            $this->transitions->transition($case, $to, $actor, $reason,
                externalMessage: $message, onPsInstruction: $onPsInstruction);
        } catch (ReasonRequired) {
            throw ValidationException::withMessages(['reason' => 'Reason is required.']);
        } catch (PsInstructionRequired) {
            throw ValidationException::withMessages(['on_ps_instruction' => 'Confirm that the Permanent Secretary instructed this rejection.']);
        } catch (InvalidTransition|StaleTransition) {
            throw ValidationException::withMessages([$field => $to === CaseStatus::WITHDRAWN
                ? 'This case cannot be withdrawn in its current status.'
                : 'This case cannot be rejected in its current status.']);
        }

        return StepResult::done($case->refresh());
    }
}
