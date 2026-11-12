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
    ) {}

    public function appliesFrom(CaseStatus $status): bool
    {
        return in_array($status, $this->from, true);
    }
}
