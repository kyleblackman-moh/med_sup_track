<?php

// Document upload, scanning and download settings (slice 8c). Every value has a
// safe default; override with the DOCUMENTS_* keys shown in .env.example.

return [

    // Private storage root for the "documents" disk (config/filesystems.php).
    'root' => env('DOCUMENTS_ROOT', storage_path('app/private/documents')),

    'max_size_kb' => (int) env('DOCUMENTS_MAX_SIZE_KB', 25600),

    'allowed_extensions' => ['pdf', 'docx', 'xlsx', 'jpg', 'jpeg', 'png'],

    'max_files_per_case' => (int) env('DOCUMENTS_MAX_FILES_PER_CASE', 100),

    'max_bytes_per_case' => (int) env('DOCUMENTS_MAX_BYTES_PER_CASE', 524288000),

    // Zip-bomb and archive guards for DOCX/XLSX.
    'zip_max_entries' => (int) env('DOCUMENTS_ZIP_MAX_ENTRIES', 2000),

    'zip_max_uncompressed_bytes' => (int) env('DOCUMENTS_ZIP_MAX_UNCOMPRESSED_BYTES', 209715200),

    // clamd (production) or eicar_dev (local/testing only; refuses other environments).
    'scanner' => env('DOCUMENTS_SCANNER', 'clamd'),

    'clamd' => [
        'host' => env('DOCUMENTS_CLAMD_HOST', '127.0.0.1'),
        'port' => (int) env('DOCUMENTS_CLAMD_PORT', 3310),
        'connect_timeout' => (int) env('DOCUMENTS_CLAMD_CONNECT_TIMEOUT', 5),
        'read_timeout' => (int) env('DOCUMENTS_CLAMD_READ_TIMEOUT', 60),
    ],

    'download_link_ttl_seconds' => (int) env('DOCUMENTS_DOWNLOAD_LINK_TTL_SECONDS', 60),

];
