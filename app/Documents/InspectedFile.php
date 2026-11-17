<?php

namespace App\Documents;

/** The server's view of an upload: type detected from content, never from the client. */
final readonly class InspectedFile
{
    public function __construct(
        public string $type,        // pdf (UI-3: new uploads are PDF only)
        public string $mime,        // detected, used as the download Content-Type
        public string $extension,   // normalised, lowercase
        public string $displayName, // sanitised; never used in a storage path
        public int $sizeBytes,
    ) {}
}
