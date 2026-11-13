<?php

namespace App\Workflow\Actions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Records that the physical hardcopy arrived, or corrects a wrong record.
 *
 * Not a status transition: it only sets the value the gate (T4) reads later.
 * Columns: hardcopy_received_on (calendar date, Port of Spain), plus who
 * recorded it (hardcopy_received_by) and when (hardcopy_received_at). All three
 * are set and cleared together, with the audit row, in one transaction.
 */
final class RecordHardcopy
{
    public const TIMEZONE = 'America/Port_of_Spain';

    /** Statuses in which hardcopy can be recorded or corrected. */
    public const STATUSES = [CaseStatus::SUBMITTED, CaseStatus::CMO_INTAKE, CaseStatus::IN_REVIEW];

    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    /** @throws ValidationException */
    public function mark(ProcurementCase $case, User $actor, string $receivedOn): ProcurementCase
    {
        return $this->locked($case, $actor, function (ProcurementCase $locked) use ($actor, $receivedOn) {
            if ($locked->hardcopy_received_on !== null) {
                throw ValidationException::withMessages([
                    'received_on' => 'The hardcopy is already recorded as received on '.$locked->hardcopy_received_on->toDateString()
                        .'. Use "Mark not received" to correct it.',
                ]);
            }

            $date = $this->validDate($locked, $receivedOn);

            $locked->forceFill([
                'hardcopy_received_on' => $date,
                'hardcopy_received_at' => CarbonImmutable::now('UTC'),
                'hardcopy_received_by' => $actor->getKey(),
                'hardcopy_receipt_method' => 'manual',
            ])->save();

            Audit::record(
                AuditAction::CaseHardcopyReceived,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                ['received_on' => $date, 'method' => 'manual'],
                $actor,
            );

            return $locked;
        });
    }

    /** @throws ValidationException */
    public function reverse(ProcurementCase $case, User $actor, string $reason): ProcurementCase
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);
        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            throw ValidationException::withMessages([
                'reason' => 'Give a reason of '.self::REASON_MIN.' to '.self::REASON_MAX.' characters.',
            ]);
        }

        return $this->locked($case, $actor, function (ProcurementCase $locked) use ($actor, $reason) {
            if ($locked->hardcopy_received_on === null) {
                throw ValidationException::withMessages(['reason' => 'The hardcopy is not recorded as received.']);
            }

            $previous = $locked->hardcopy_received_on->toDateString();
            $previousBy = $locked->hardcopy_received_by !== null ? (int) $locked->hardcopy_received_by : null;

            $locked->forceFill([
                'hardcopy_received_on' => null,
                'hardcopy_received_at' => null,
                'hardcopy_received_by' => null,
                'hardcopy_receipt_method' => null,
            ])->save();

            Audit::record(
                AuditAction::CaseHardcopyReversed,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                ['reason' => $reason, 'previous_received_on' => $previous, 'previous_recorded_by' => $previousBy],
                $actor,
            );

            return $locked;
        });
    }

    /**
     * Authorise (hardcopy.confirm through CasePolicy::act), lock the row, check
     * the status, then run $change. Everything rolls back on any failure.
     */
    private function locked(ProcurementCase $case, User $actor, callable $change): ProcurementCase
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $case);

        return DB::transaction(function () use ($case, $gate, $change) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            $gate->authorize('act', [$locked, Permission::HardcopyConfirm->value]);

            if (! in_array($locked->status, self::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'hardcopy' => 'The hardcopy can be recorded only while the case is Submitted, in CMO intake or In review.',
                ]);
            }

            return $change($locked)->refresh();
        });
    }

    /** Today or earlier (Port of Spain), and not before the day the case was submitted. */
    private function validDate(ProcurementCase $case, string $value): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TIMEZONE);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['received_on' => 'Enter the date the hardcopy was received.']);
        }

        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();
        if ($value > $today) {
            throw ValidationException::withMessages(['received_on' => 'The date received cannot be in the future.']);
        }

        $submitted = $case->submitted_at?->copy()->setTimezone(self::TIMEZONE)->toDateString();
        if ($submitted !== null && $value < $submitted) {
            throw ValidationException::withMessages(['received_on' => "The date received cannot be before the case was submitted ({$submitted})."]);
        }

        return $value;
    }
}
