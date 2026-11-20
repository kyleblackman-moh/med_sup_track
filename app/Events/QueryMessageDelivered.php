<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * OVERNIGHT-2: a query message was delivered to the other side (posted on a direct route, or forwarded by the CMO).
 * Ids only: listeners load what they need.
 */
class QueryMessageDelivered
{
    use Dispatchable;

    public function __construct(
        public readonly string $caseId,
        public readonly string $queryId,
        public readonly string $messageId,
    ) {}
}
