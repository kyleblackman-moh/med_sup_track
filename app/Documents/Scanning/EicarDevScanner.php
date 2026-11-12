<?php

namespace App\Documents\Scanning;

use RuntimeException;

/**
 * Development stand-in for clamd: infected only when the file contains the
 * standard EICAR test string. Refuses to exist outside local and testing.
 */
final class EicarDevScanner implements Scanner
{
    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public function __construct()
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('EicarDevScanner may only be used in local or testing environments.');
        }
    }

    public function scan(string $absolutePath): ScanResult
    {
        $contents = @file_get_contents($absolutePath);

        if ($contents === false) {
            return ScanResult::error('File could not be read for scanning.');
        }

        return str_contains($contents, self::EICAR)
            ? ScanResult::infected('Eicar-Test-Signature')
            : ScanResult::clean();
    }
}
