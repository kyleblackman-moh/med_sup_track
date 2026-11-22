<?php

namespace App\Workflow;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\ReturnKind;
use App\Enums\TrackStatus;
use App\Events\CaseTransitioned;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Workflow\Exceptions\InvalidTransition;
use App\Workflow\Exceptions\PsInstructionRequired;
use App\Workflow\Exceptions\ReasonRequired;
use App\Workflow\Exceptions\StaleTransition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The only way to change a case's status (spec 6.6). Workflow rules
 * (TransitionRules) and access control (CasePolicy::act) meet here.
 */
final class CaseTransitionService
{
    /**
     * @param  ReturnKind|null  $returnKind  Slice 10d: only for a PS return
     *                                       (WITH_PS -> RETURNED_TO_CMO); written on the same history row.
     * @param  string|null  $externalMessage  TERMINAL-1: the optional message to the vendor, only on a
     *                                        withdrawal or final rejection. The only text the vendor sees.
     * @param  bool|null  $onPsInstruction  TERMINAL-1: required (true) on a final rejection, null otherwise.
     */
    public function transition(
        ProcurementCase $case,
        CaseStatus $to,
        User $actor,
        ?string $reason = null,
        ?ReturnKind $returnKind = null,
        ?string $externalMessage = null,
        ?bool $onPsInstruction = null,
    ): ProcurementCase {
        $from = $case->status instanceof CaseStatus ? $case->status : null;

        // TERMINAL-1: nothing leaves CLOSED, WITHDRAWN or REJECTED_FINAL, whatever the rules list says.
        if ($from === null || $from->isTerminal()) {
            throw new InvalidTransition($from, $to);
        }

        if ($returnKind !== null && ! ($from === CaseStatus::WITH_PS && $to === CaseStatus::RETURNED_TO_CMO)) {
            throw new InvalidArgumentException('return_kind is recorded only on a PS return (WITH_PS -> RETURNED_TO_CMO).');
        }

        $externalMessage = $externalMessage === null || trim($externalMessage) === '' ? null : trim($externalMessage);
        if ($externalMessage !== null && ! TerminalCloseOut::applies($to)) {
            throw new InvalidArgumentException('A message to the vendor is recorded only on a withdrawal or final rejection.');
        }
        if ($onPsInstruction !== null && $to !== CaseStatus::REJECTED_FINAL) {
            throw new InvalidArgumentException('on_ps_instruction is recorded only on a final rejection.');
        }

        // 1. Rule (findFor: T15V and T15 share SUBMITTED -> WITHDRAWN; the actor's permission picks)
        $rule = TransitionRules::findFor($from, $to, $actor)
            ?? throw new InvalidTransition($from, $to);

        // 2. Permission + visibility + eligibility (throws AuthorizationException)
        Gate::forUser($actor)->authorize('act', [$case, $rule->permission]);

        // 3. Guard on the in-memory case (re-run on the locked row below)
        $this->runGuard($rule, $case, $actor);

        // 4. Reason. case_transitions.reason is nvarchar(max), so there is no
        //    column length to enforce here.
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        if ($rule->requiresReason && $reason === null) {
            throw new ReasonRequired($rule->code);
        }

        // TERMINAL-1 (Q3 assumption): a final rejection is on the PS's instruction.
        if ($to === CaseStatus::REJECTED_FINAL && $onPsInstruction !== true) {
            throw new PsInstructionRequired;
        }

        // 5. Write status, history and audit atomically. The audit write fails closed:
        //    if it throws, the whole transition rolls back.
        DB::transaction(function () use ($case, $rule, $from, $to, $actor, $reason, $returnKind, $externalMessage, $onPsInstruction) {
            $locked = ProcurementCase::withoutGlobalScopes()
                ->whereKey($case->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== $from) {
                throw new StaleTransition($from, $locked->status instanceof CaseStatus ? $locked->status : null);
            }

            $this->runGuard($rule, $locked, $actor);

            ProcurementCase::allowStatusWrite(function () use ($locked, $rule, $to) {
                $locked->status = $to;
                $locked->current_holder_role = $rule->holderRole;
                $locked->status_changed_at = CarbonImmutable::now('UTC');

                foreach ($rule->resetTracks as $track) {
                    $locked->{$track} = TrackStatus::PENDING;
                }

                $locked->save();
            });

            (new CaseTransition)->forceFill([
                'case_id' => $locked->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->getKey(),
                // TODO(delegation, spec 8.5): set acting_for_id when delegations exist.
                'acting_for_id' => null,
                'reason' => $reason,
                'return_kind' => $returnKind?->value,
                'external_message' => $externalMessage,
                'on_ps_instruction' => $onPsInstruction,
                'created_at' => CarbonImmutable::now('UTC'),
            ])->save();

            // TERMINAL-1: open queries cancelled, active assignments closed, in this transaction.
            if (TerminalCloseOut::applies($to)) {
                TerminalCloseOut::run($locked, $to, $actor);
            }

            Audit::record(
                AuditAction::CaseTransitioned,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                // Never the reason or message text: the auditor and system admin see no
                // case content. The text lives only in case_transitions.
                [
                    'from' => $from->value,
                    'to' => $to->value,
                    'has_reason' => $reason !== null,
                    'reason_length' => $reason === null ? 0 : mb_strlen($reason),
                    'rule' => $rule->code,
                    ...(TerminalCloseOut::applies($to) ? [
                        'has_external_message' => $externalMessage !== null,
                        'external_message_length' => $externalMessage === null ? 0 : mb_strlen($externalMessage),
                    ] : []),
                    ...($onPsInstruction !== null ? ['on_ps_instruction' => $onPsInstruction] : []),
                ],
                $actor,
            );

            // 6. Only once the transition has committed.
            DB::afterCommit(fn () => CaseTransitioned::dispatch($locked, $from, $to, $actor));
        });

        return $case->refresh();
    }

    private function runGuard(TransitionRule $rule, ProcurementCase $case, User $actor): void
    {
        if ($rule->guard !== null) {
            ($rule->guard)($case, $actor);
        }
    }
}
