<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A document failed the malware scan and was moved to quarantine. No listener yet. */
class DocumentQuarantined
{
    use Dispatchable;

    public function __construct(public readonly string $documentId) {}
}
