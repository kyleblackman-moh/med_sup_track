<?php

namespace App\Events;

use App\Enums\CaseStatus;
use App\Models\ProcurementCase;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after a case transition has committed. */
class CaseTransitioned
{
    use Dispatchable;

    public function __construct(
        public readonly ProcurementCase $case,
        public readonly CaseStatus $from,
        public readonly CaseStatus $to,
        public readonly User $actor,
    ) {}
}
