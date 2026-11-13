<?php

namespace App\Workflow\Actions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\ReviewDesk;
use App\Models\CaseAssignment;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Workflow\CaseTransitionService;
use App\Workflow\EligibleReviewers;
use App\Workflow\Exceptions\InvalidTransition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Send for review (T3, CMO_INTAKE -> IN_REVIEW), in one transaction: lock the
 * case, check the chosen reviewers, transition (TransitionRules T3 resets both
 * tracks to PENDING), give each reviewer one active assignment, and audit.
 * Any failure, including an audit write, rolls back all of it (fail closed).
 *
 * The intake assignment from slice 9b is left as it is. Hardcopy is not
 * required here; it only gates READY_FOR_FILE (T4).
 *
 * T3 only. CaseTransitionService itself never creates assignments.
 */
final class ForwardForReview
{
    public const MIN_PER_DESK = 1;

    public const MAX_PER_DESK = 3;

    public const INSTRUCTION_MAX = 500;

    public function __construct(
        private readonly CaseTransitionService $transitions,
        private readonly EligibleReviewers $reviewers,
    ) {}

    /**
     * @param  array<mixed>  $technocratIds
     * @param  array<mixed>  $cabIds
     *
     * @throws ValidationException
     */
    public function handle(ProcurementCase $case, User $actor, array $technocratIds, array $cabIds, ?string $instruction = null): ForwardResult
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $case);

        $instruction = $instruction === null || trim($instruction) === '' ? null : trim($instruction);
        if ($instruction !== null && mb_strlen($instruction) > self::INSTRUCTION_MAX) {
            throw ValidationException::withMessages(['instruction' => 'The instruction can be at most '.self::INSTRUCTION_MAX.' characters.']);
        }

        return DB::transaction(function () use ($case, $actor, $gate, $technocratIds, $cabIds, $instruction) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            // Permission, visibility and eligibility before anything is revealed.
            $gate->authorize('act', [$locked, Permission::CaseRouteToReview->value]);

            if ($locked->status !== CaseStatus::CMO_INTAKE) {
                return $this->alreadyForwarded($locked)
                    ?? throw new InvalidTransition($locked->status instanceof CaseStatus ? $locked->status : null, CaseStatus::IN_REVIEW);
            }

            $chosen = [
                ReviewDesk::Technical->value => $this->chosen(ReviewDesk::Technical, $technocratIds),
                ReviewDesk::Funding->value => $this->chosen(ReviewDesk::Funding, $cabIds),
            ];

            $this->transitions->transition($locked, CaseStatus::IN_REVIEW, $actor, $instruction);

            foreach (ReviewDesk::cases() as $desk) {
                foreach ($chosen[$desk->value] as $user) {
                    $this->assign($locked, $actor, $desk, $user);
                }
            }

            Audit::record(
                AuditAction::CaseForwardedForReview,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                [
                    'technocrats' => count($chosen[ReviewDesk::Technical->value]),
                    'cab_officers' => count($chosen[ReviewDesk::Funding->value]),
                    'instruction_given' => $instruction !== null,
                ],
                $actor,
            );

            return ForwardResult::forwarded($locked->refresh());
        });
    }

    /** The case was sent before: who did it and when, from the T3 history row. */
    private function alreadyForwarded(ProcurementCase $case): ?ForwardResult
    {
        $row = CaseTransition::query()
            ->with('actor:id,name')
            ->where('case_id', $case->getKey())
            ->where('from_status', CaseStatus::CMO_INTAKE->value)
            ->where('to_status', CaseStatus::IN_REVIEW->value)
            ->orderBy('id')
            ->first();

        return $row ? ForwardResult::alreadyForwarded($case, $row->actor?->name, $row->created_at) : null;
    }

    /**
     * Duplicates collapse; then 1 to 3 users, each active, staff and holding the
     * desk's permission.
     *
     * @param  array<mixed>  $ids
     * @return list<User>
     *
     * @throws ValidationException
     */
    private function chosen(ReviewDesk $desk, array $ids): array
    {
        $field = $desk->field();
        $clean = [];

        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $clean[(int) $id] = (int) $id;
            } else {
                throw ValidationException::withMessages([$field => "Choose from the listed {$desk->people()}."]);
            }
        }

        if (count($clean) < self::MIN_PER_DESK || count($clean) > self::MAX_PER_DESK) {
            throw ValidationException::withMessages([$field => 'Choose '.self::MIN_PER_DESK.' to '.self::MAX_PER_DESK." {$desk->people()}."]);
        }

        $eligible = $this->reviewers->among($desk, array_values($clean));
        if (count($eligible) !== count($clean)) {
            throw ValidationException::withMessages([$field => "Choose from the listed {$desk->people()}. One or more of the people chosen cannot review this desk."]);
        }

        return array_values(array_map(fn (int $id) => $eligible[$id], $clean));
    }

    private function assign(ProcurementCase $case, User $actor, ReviewDesk $desk, User $reviewer): void
    {
        $assignment = (new CaseAssignment)->forceFill([
            'case_id' => $case->getKey(),
            'role' => $desk->assignmentRole(),
            'purpose' => $desk->value,
            'user_id' => $reviewer->getKey(),
            'assigned_by' => $actor->getKey(),
            'assigned_at' => CarbonImmutable::now('UTC'),
        ]);
        $assignment->save();

        Audit::record(
            AuditAction::CaseAssignmentCreated,
            ['type' => 'case_assignment', 'id' => $assignment->getKey()],
            $case->getKey(),
            ['role' => $desk->assignmentRole(), 'user_id' => (int) $reviewer->getKey(), 'purpose' => $desk->value],
            $actor,
        );
    }
}
