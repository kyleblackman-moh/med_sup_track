<?php

namespace App\Notifications\Vendor;

use App\Models\ProcurementCase;
use App\Workflow\Actions\EndCase;

/**
 * The case entered REJECTED_FINAL. The email adds the Ministry's message to the
 * vendor (or "No further details provided."), read from the transition when
 * the email is built; the internal reason is never read.
 */
final class VendorApplicationRejected extends VendorNotification
{
    protected function mailLines(): array
    {
        $case = ProcurementCase::withoutGlobalScopes()->find($this->caseId);
        $outcome = $case === null ? null : EndCase::vendorOutcome($case);

        return ['Message from the Ministry: '.($outcome['message'] ?? EndCase::NO_DETAILS)];
    }

    public function event(): string
    {
        return 'vendor_rejected';
    }

    protected function subject(): string
    {
        return 'Application '.$this->reference().' Not Approved';
    }

    protected function sentence(): string
    {
        return 'Your application '.$this->reference().' was not approved. Log in for details.';
    }
}
