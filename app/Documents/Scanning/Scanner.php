<?php

namespace App\Documents\Scanning;

/** Malware scanner. Implementations must never report clean unless the engine said so. */
interface Scanner
{
    public function scan(string $absolutePath): ScanResult;
}
