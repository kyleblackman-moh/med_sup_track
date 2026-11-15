<?php

namespace App\Workflow\Actions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Workflow\CaseTransitionService;
use App\Workflow\Exceptions\GateNotPassed;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * T13, NON_OBJECTION_GRANTED -> LETTER_ISSUED (slice 10f): record that the
 * non-objection letter was issued.
 *
 * ASSUMPTION (confirm with the CMO's Office): the letter is prepared and signed
 * outside the system and sent by the CMO's Office by email and/or hardcopy.
 * Both channels are not required, but at least one must be recorded. The
 * reference format is unknown, so it is optional free text (1 to 40
 * characters, not unique).
 *
 * In one transaction: lock, letter.issue through CasePolicy::act, status
 * re-check (an already-issued case gets a friendly result), write the letter
 * columns, then transition (the T13 guard reads those columns), then audit
 * with dates and flags only. Fail closed.
 */
final class IssueLetter
{
    public const REFERENCE_MAX = 40;

    public function __construct(private readonly CaseTransitionService $transitions) {}

    /** @throws ValidationException */
    public function handle(ProcurementCase $case, User $actor, ?string $letterDate, ?string $reference = null, ?string $emailedOn = null, ?string $hardcopySentOn = null): StepResult
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $case);

        $letterDate = $this->date('letter_date', $letterDate, 'Enter the date on the letter.', required: true);
        $emailedOn = $this->date('emailed_on', $emailedOn, 'Enter the date the letter was emailed.');
        $hardcopySentOn = $this->date('hardcopy_sent_on', $hardcopySentOn, 'Enter the date the hardcopy was sent.');
        if ($emailedOn === null && $hardcopySentOn === null) {
            throw ValidationException::withMessages(['emailed_on' => 'Record when the letter was emailed, when the hardcopy was sent, or both.']);
        }

        $reference = $reference === null || trim($reference) === '' ? null : trim($reference);
        if ($reference !== null && mb_strlen($reference) > self::REFERENCE_MAX) {
            throw ValidationException::withMessages(['letter_reference' => 'The reference can be at most '.self::REFERENCE_MAX.' characters.']);
        }

        return DB::transaction(function () use ($case, $actor, $gate, $letterDate, $reference, $emailedOn, $hardcopySentOn) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            $gate->authorize('act', [$locked, Permission::LetterIssue->value]);

            if ($locked->status !== CaseStatus::NON_OBJECTION_GRANTED) {
                return self::alreadyIssued($locked) ?? throw ValidationException::withMessages([
                    'issue_letter' => 'The letter can be recorded only once non-objection is granted.',
                ]);
            }

            $locked->forceFill([
                'letter_date' => $letterDate,
                'letter_reference' => $reference,
                'letter_emailed_on' => $emailedOn,
                'letter_hardcopy_sent_on' => $hardcopySentOn,
                'letter_recorded_by' => $actor->getKey(),
                'letter_recorded_at' => CarbonImmutable::now('UTC'),
            ])->save();

            try {
                $this->transitions->transition($locked, CaseStatus::LETTER_ISSUED, $actor);
            } catch (GateNotPassed $e) {
                throw ValidationException::withMessages([
                    'issue_letter' => implode('; ', array_map(fn (string $k) => TransitionRules::GUARD_MESSAGES[$k] ?? $k, $e->missing)).'.',
                ]);
            }

            // Dates and a flag only: the reference is typed by hand.
            Audit::record(
                AuditAction::CaseLetterIssued,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                [
                    'letter_date' => $letterDate,
                    'emailed_on' => $emailedOn,
                    'hardcopy_sent_on' => $hardcopySentOn,
                    'has_reference' => $reference !== null,
                ],
                $actor,
            );

            return StepResult::done($locked->refresh());
        });
    }

    /** The T13 row: who recorded the letter and when. */
    public static function alreadyIssued(ProcurementCase $case): ?StepResult
    {
        $row = CaseTransition::query()->with('actor:id,name')
            ->where('case_id', $case->getKey())
            ->where('from_status', CaseStatus::NON_OBJECTION_GRANTED->value)
            ->where('to_status', CaseStatus::LETTER_ISSUED->value)
            ->orderByDesc('id')
            ->first();

        return $row ? StepResult::already($case, $row->actor?->name, $row->created_at) : null;
    }

    /** A Y-m-d date, today or earlier in Port of Spain; null when optional and empty. */
    private function date(string $field, ?string $value, string $message, bool $required = false): ?string
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => $message]);
            }

            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, RecordHardcopy::TIMEZONE);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => $message]);
        }

        if ($value > CarbonImmutable::now(RecordHardcopy::TIMEZONE)->toDateString()) {
            throw ValidationException::withMessages([$field => 'The date cannot be in the future.']);
        }

        return $value;
    }
}
