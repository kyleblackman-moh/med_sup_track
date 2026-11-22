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
 * - CANCELLED (TERMINAL-1): the case was withdrawn or finally rejected while
 *   the query was still open; cancel_reason says which. Ends the query like CLOSED.
 */
enum QueryStatus: string
{
    case OPEN = 'OPEN';
    case AWAITING_FORWARD = 'AWAITING_FORWARD';
    case ANSWERED = 'ANSWERED';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';

    /** CLOSED or CANCELLED: nobody can add to it. */
    public function isEnded(): bool
    {
        return $this === self::CLOSED || $this === self::CANCELLED;
    }

    /** @return list<string> */
    public static function endedValues(): array
    {
        return [self::CLOSED->value, self::CANCELLED->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::AWAITING_FORWARD => 'Awaiting Forward',
            self::ANSWERED => 'Answered',
            self::CLOSED => 'Closed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::OPEN, self::AWAITING_FORWARD => 'info',
            self::ANSWERED => 'success',
            self::CLOSED, self::CANCELLED => 'neutral',
        };
    }
}
