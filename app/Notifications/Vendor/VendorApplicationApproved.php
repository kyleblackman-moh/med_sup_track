<?php

namespace App\Notifications\Vendor;

/** The case entered NON_OBJECTION_GRANTED. */
final class VendorApplicationApproved extends VendorNotification
{
    public function event(): string
    {
        return 'vendor_approved';
    }

    protected function subject(): string
    {
        return 'Non-Objection Granted for '.$this->reference();
    }

    protected function sentence(): string
    {
        return 'Non-objection has been granted for '.$this->reference().'.';
    }
}
