<?php

namespace App\Notifications\Vendor;

/** The case entered REJECTED_FINAL. The portal shows a reason only if one is marked external. */
final class VendorApplicationRejected extends VendorNotification
{
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
