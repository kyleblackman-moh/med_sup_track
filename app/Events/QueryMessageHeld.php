<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * OVERNIGHT-2: a query message was posted on a via-CMO route and is held for the CMO to forward.
 * Ids only: listeners load what they need.
 */
class QueryMessageHeld
{
    use Dispatchable;

    public function __construct(
        public readonly string $caseId,
        public readonly string $queryId,
        public readonly string $messageId,
    ) {}
}
