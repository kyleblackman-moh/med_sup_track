<?php

namespace App\Enums;

/**
 * OVERNIGHT-2 (D1): a query's own status. Queries are an overlay on the case,
 * never a case status; open queries do not block case transitions.
 *
 * - OPEN: delivered to the recipient, waiting on their answer.
 * - AWAITING_FORWARD: a via-CMO message is held until the CMO forwards it.
 * - ANSWERED: the recipient's answer was delivered to the raiser.
 * - CLOSED: either side or the CMO closed it.
 */
enum QueryStatus: string
{
    case OPEN = 'OPEN';
    case AWAITING_FORWARD = 'AWAITING_FORWARD';
    case ANSWERED = 'ANSWERED';
    case CLOSED = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::AWAITING_FORWARD => 'Awaiting Forward',
            self::ANSWERED => 'Answered',
            self::CLOSED => 'Closed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::OPEN, self::AWAITING_FORWARD => 'info',
            self::ANSWERED => 'success',
            self::CLOSED => 'neutral',
        };
    }
}
