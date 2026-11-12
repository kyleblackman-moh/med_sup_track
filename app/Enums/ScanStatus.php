<?php

namespace App\Enums;

/** documents.scan_status. Only CLEAN documents are ever served. */
enum ScanStatus: string
{
    case PENDING = 'PENDING';
    case CLEAN = 'CLEAN';
    case INFECTED = 'INFECTED';
    case ERROR = 'ERROR';

    /** Vendor-facing label. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Scanning',
            self::CLEAN => 'Ready',
            self::INFECTED, self::ERROR => 'Rejected',
        };
    }
}
