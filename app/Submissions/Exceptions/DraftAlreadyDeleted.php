<?php

namespace App\Submissions\Exceptions;

use DomainException;

/** The draft was erased by an earlier request (for example a double submit). */
class DraftAlreadyDeleted extends DomainException
{
    public function __construct()
    {
        parent::__construct('This draft has already been deleted.');
    }
}
