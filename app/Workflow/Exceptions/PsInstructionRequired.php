<?php

namespace App\Workflow\Exceptions;

use DomainException;

/** TERMINAL-1: T16 is recorded only on the instruction of the Permanent Secretary. */
class PsInstructionRequired extends DomainException
{
    public function __construct()
    {
        parent::__construct('A final rejection must be recorded as on the instruction of the Permanent Secretary.');
    }
}
