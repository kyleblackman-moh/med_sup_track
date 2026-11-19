<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** NOTIF-1: the hardcopy of a case was recorded as received (dispatched after commit by RecordHardcopy::mark). */
class HardcopyRecorded
{
    use Dispatchable;

    public function __construct(public readonly string $caseId) {}
}
