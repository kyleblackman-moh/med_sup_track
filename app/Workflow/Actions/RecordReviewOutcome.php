<?php

namespace App\Workflow\Actions;

use App\Enums\CaseStatus;
use App\Enums\ReviewDesk;
use App\Enums\ReviewOutcome;
use App\Models\CaseAssignment;
use App\Models\CaseTrackEvent;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Workflow\TrackCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Shared implementation of RecordTechnicalReview and RecordFundingConfirmation
 * (slice 10b). In one transaction: lock the case, check it is IN_REVIEW, insert
 * one case_track_events row, recompute the track (TrackCalculator), write it to
 * the case and audit. Any failure, including the audit write, rolls back all
 * of it (fail closed).
 *
 * Who may record: the desk's permission through CasePolicy::act AND an active
 * assignment for that desk (assignment purpose). act() alone would also admit a
 * case.view_all holder without an assignment.
 *
 * A reviewer may change their outcome while the case is IN_REVIEW: each change
 * is a new row; nothing is edited or deleted.
 */
final class RecordReviewOutcome
{
    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    /** @throws ValidationException|AuthorizationException */
    public function handle(ReviewDesk $desk, ProcurementCase $case, User $actor, string $outcome, ?string $reason): ProcurementCase
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $case);

        [$chosen, $reason] = $this->validInput($desk, $outcome, $reason);

        return DB::transaction(function () use ($desk, $case, $actor, $gate, $chosen, $reason) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            $gate->authorize('act', [$locked, $desk->permission()->value]);

            if ($locked->status !== CaseStatus::IN_REVIEW) {
                throw ValidationException::withMessages([
                    'review' => 'The review is closed: outcomes can be recorded only while the case is In review.',
                ]);
            }

            if (! $this->isActiveReviewer($locked, $desk, $actor)) {
                throw new AuthorizationException('You are not assigned to this review.');
            }

            $event = (new CaseTrackEvent)->forceFill([
                'case_id' => $locked->getKey(),
                'track' => $desk,
                'outcome' => $chosen,
                'reason' => $reason,
                'actor_id' => $actor->getKey(),
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
            $event->save();

            $previous = $locked->{$desk->track()};
            $locked->forceFill([$desk->track() => TrackCalculator::compute($locked, $desk)])->save();

            // Outcome and track values only; the reason text stays out of the audit log.
            Audit::record(
                $desk->auditAction(),
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                [
                    'outcome' => $chosen->value,
                    'previous_track' => $previous?->value,
                    'track' => $locked->{$desk->track()}->value,
                    'reason_given' => $reason !== null,
                    'event_id' => (int) $event->getKey(),
                ],
                $actor,
            );

            return $locked->refresh();
        });
    }

    /**
     * @return array{0: ReviewOutcome, 1: string|null}
     *
     * @throws ValidationException
     */
    private function validInput(ReviewDesk $desk, string $outcome, ?string $reason): array
    {
        $chosen = ReviewOutcome::tryFrom($outcome);
        if ($chosen === null || $chosen->desk() !== $desk) {
            throw ValidationException::withMessages(['outcome' => 'Choose an outcome.']);
        }

        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        $length = $reason === null ? 0 : mb_strlen($reason);

        if (! $chosen->isPositive() && ($length < self::REASON_MIN || $length > self::REASON_MAX)) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of '.self::REASON_MIN.' to '.self::REASON_MAX.' characters.']);
        }
        if ($length > self::REASON_MAX) {
            throw ValidationException::withMessages(['reason' => 'The note can be at most '.self::REASON_MAX.' characters.']);
        }

        return [$chosen, $reason];
    }

    private function isActiveReviewer(ProcurementCase $case, ReviewDesk $desk, User $actor): bool
    {
        return CaseAssignment::query()
            ->where('case_id', $case->getKey())
            ->where('purpose', $desk->value)
            ->where('user_id', $actor->getKey())
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->exists();
    }
}
