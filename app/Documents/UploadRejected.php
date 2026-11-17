<?php

namespace App\Documents;

use RuntimeException;

/** An upload failed inspection. The message is safe to show; it never echoes file content. */
class UploadRejected extends RuntimeException
{
    private const MESSAGES = [
        'empty' => 'The file is empty.',
        'too_large' => 'The file is larger than the allowed size.',
        'bad_name' => 'The file name contains characters that are not allowed.',
        // UI-3: PDF only. Not a .pdf name, not PDF bytes, or not detected as application/pdf.
        'extension_not_allowed' => 'Only PDF files are accepted.',
        'unknown_type' => 'Only PDF files are accepted.',
        'type_mismatch' => 'Only PDF files are accepted.',
        'unreadable' => 'The file could not be read.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'The file was not accepted.');
    }
}
