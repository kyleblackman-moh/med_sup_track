<?php

namespace App\Notifications\Vendor;

/** An external query became visible to the vendor (a direct Technocrat query, or after the CMO forwards it). */
final class VendorQueryReceived extends VendorNotification
{
    public function event(): string
    {
        return 'vendor_query_received';
    }

    protected function subject(): string
    {
        return 'New Query on '.$this->reference();
    }

    protected function sentence(): string
    {
        return 'You have a new query on '.$this->reference().'. Log in to respond.';
    }
}
