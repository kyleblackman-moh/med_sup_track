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
        'extension_not_allowed' => 'This type of file is not allowed. Upload a PDF, Word (.docx), Excel (.xlsx), JPEG or PNG file.',
        'unknown_type' => 'The file content is not a PDF, Word (.docx), Excel (.xlsx), JPEG or PNG file.',
        'type_mismatch' => 'The file content does not match its extension.',
        'not_office_document' => 'The file is not a valid Word or Excel document.',
        'macro_content' => 'Documents containing macros are not allowed.',
        'unsafe_zip_entry' => 'The document contains unsafe content.',
        'zip_too_many_entries' => 'The document is too complex to accept.',
        'zip_too_large' => 'The document expands to more than the allowed size.',
        'unreadable' => 'The file could not be read.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'The file was not accepted.');
    }
}
