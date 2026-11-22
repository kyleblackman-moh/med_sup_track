<?php

namespace App\Notifications\Vendor;

/** The vendor's own reply to a query was submitted. */
final class VendorQueryResponseSent extends VendorNotification
{
    public function event(): string
    {
        return 'vendor_response_sent';
    }

    protected function subject(): string
    {
        return 'Response Sent on '.$this->reference();
    }

    protected function sentence(): string
    {
        return 'Your response on '.$this->reference().' was sent to the Ministry.';
    }
}
