<?php

namespace App\Workflow;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\TrackStatus;
use App\Events\CaseTransitioned;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Workflow\Exceptions\InvalidTransition;
use App\Workflow\Exceptions\ReasonRequired;
use App\Workflow\Exceptions\StaleTransition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The only way to change a case's status (spec 6.6). Workflow rules
 * (TransitionRules) and access control (CasePolicy::act) meet here.
 */
final class CaseTransitionService
{
    public function transition(ProcurementCase $case, CaseStatus $to, User $actor, ?string $reason = null): ProcurementCase
    {
        $from = $case->status instanceof CaseStatus ? $case->status : null;

        // 1. Rule
        $rule = ($from ? TransitionRules::find($from, $to) : null)
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

        // 5. Write status, history and audit atomically. The audit write fails closed:
        //    if it throws, the whole transition rolls back.
        DB::transaction(function () use ($case, $rule, $from, $to, $actor, $reason) {
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
                'created_at' => CarbonImmutable::now('UTC'),
            ])->save();

            Audit::record(
                AuditAction::CaseTransitioned,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                ['from' => $from->value, 'to' => $to->value, 'reason' => $reason, 'rule' => $rule->code],
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
