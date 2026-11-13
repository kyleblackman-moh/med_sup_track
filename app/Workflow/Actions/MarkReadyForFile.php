<?php

namespace App\Workflow\Actions;

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\CaseTransitionService;
use App\Workflow\Exceptions\GateNotPassed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * T4, IN_REVIEW -> READY_FOR_FILE (slice 10b). The CMO clicks; nothing moves
 * automatically when the third tick lands. The existing T4 guard
 * (TransitionRules::gateMissing) decides; this only turns its missing list
 * into plain English.
 */
final class MarkReadyForFile
{
    /** GateNotPassed keys to the messages shown to staff. */
    public const MESSAGES = [
        'technical' => 'Technical review is not favourable yet',
        'funding' => 'Funding is not confirmed yet',
        'hardcopy' => 'Hardcopy has not been received',
    ];

    public function __construct(private readonly CaseTransitionService $transitions) {}

    /** @param  list<string>  $missing  @return list<string> */
    public static function messages(array $missing): array
    {
        return array_values(array_map(fn (string $key) => self::MESSAGES[$key] ?? $key, $missing));
    }

    /** @throws ValidationException */
    public function handle(ProcurementCase $case, User $actor): ProcurementCase
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $case);

        return DB::transaction(function () use ($case, $actor, $gate) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            $gate->authorize('act', [$locked, Permission::FilePrepare->value]);

            if ($locked->status !== CaseStatus::IN_REVIEW) {
                throw ValidationException::withMessages([
                    'gate' => 'The case can be marked ready for file only while it is In review.',
                ]);
            }

            try {
                $this->transitions->transition($locked, CaseStatus::READY_FOR_FILE, $actor);
            } catch (GateNotPassed $e) {
                // One message: Inertia passes only the first message per key to the page.
                throw ValidationException::withMessages([
                    'gate' => 'Not ready for file: '.implode('; ', self::messages($e->missing)).'.',
                ]);
            }

            return $locked->refresh();
        });
    }
}
