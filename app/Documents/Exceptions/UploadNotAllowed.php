<?php

namespace App\Documents\Exceptions;

use DomainException;

/** Documents can be added or withdrawn only while the case is a DRAFT. */
class UploadNotAllowed extends DomainException
{
    public function __construct(string $message = 'Documents can only be changed while the request is a draft.')
    {
        parent::__construct($message);
    }
}
