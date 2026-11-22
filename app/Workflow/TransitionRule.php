<?php

namespace App\Workflow;

use App\Enums\CaseStatus;
use App\Models\ProcurementCase;
use App\Models\User;
use Closure;

/**
 * One row of the transitions table (spec 6.5). See TransitionRules for the list.
 */
final readonly class TransitionRule
{
    /**
     * @param  list<CaseStatus>  $from
     * @param  list<string>  $resetTracks  Track columns set back to PENDING.
     * @param  (Closure(ProcurementCase, User): void)|null  $guard  Throws on failure.
     * @param  list<string>  $pendingGuards  Guards the spec requires that later slices will enforce.
     * @param  string|null  $enabledBy  A boolean config key that can switch the rule off (read on every lookup).
     */
    public function __construct(
        public string $code,
        public array $from,
        public CaseStatus $to,
        public string $permission,
        public bool $requiresReason = false,
        public ?string $holderRole = null,
        public bool $enabled = true,
        public array $resetTracks = [],
        public ?Closure $guard = null,
        public array $pendingGuards = [],
        public ?string $enabledBy = null,
    ) {}

    /** Enabled in code and, when it names one, by its config flag. */
    public function isEnabled(): bool
    {
        return $this->enabled && ($this->enabledBy === null || (bool) config($this->enabledBy));
    }

    public function appliesFrom(CaseStatus $status): bool
    {
        return in_array($status, $this->from, true);
    }
}
