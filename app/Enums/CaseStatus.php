<?php

namespace App\Enums;

/**
 * Main case statuses (spec 6.2). Case names equal their values.
 *
 * Status changes go only through the transition service (slice 7).
 */
enum CaseStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case CMO_INTAKE = 'CMO_INTAKE';
    case IN_REVIEW = 'IN_REVIEW';
    case READY_FOR_FILE = 'READY_FOR_FILE';
    case FILE_PREPARED = 'FILE_PREPARED';
    case WITH_LEGAL = 'WITH_LEGAL';
    case WITH_PS = 'WITH_PS';
    case NON_OBJECTION_GRANTED = 'NON_OBJECTION_GRANTED';
    case LETTER_ISSUED = 'LETTER_ISSUED';
    case CLOSED = 'CLOSED';
    case RETURNED_TO_CMO = 'RETURNED_TO_CMO';
    case WITHDRAWN = 'WITHDRAWN';
    case REJECTED_FINAL = 'REJECTED_FINAL';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::CLOSED, self::WITHDRAWN, self::REJECTED_FINAL => true,
            default => false,
        };
    }
}
