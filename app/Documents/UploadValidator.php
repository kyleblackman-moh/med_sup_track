<?php

namespace App\Documents;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Inspects an upload. Never trusts the client's name, extension or mime type:
 * the type is detected from the file's bytes and must agree with the extension.
 */
final class UploadValidator
{
    private const MIMES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpeg' => 'image/jpeg',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** extension => detected type it must match */
    private const EXTENSION_TYPES = [
        'pdf' => 'pdf',
        'png' => 'png',
        'jpg' => 'jpeg',
        'jpeg' => 'jpeg',
        'docx' => 'docx',
        'xlsx' => 'xlsx',
    ];

    /** @throws UploadRejected */
    public function inspect(UploadedFile $file): InspectedFile
    {
        $clientName = (string) $file->getClientOriginalName();

        if (preg_match('/[\x00-\x1F\x7F]/', $clientName)) {
            throw new UploadRejected('bad_name');
        }

        $path = $file->getRealPath();
        $size = $path !== false ? (int) filesize($path) : 0;

        if ($size === 0) {
            throw new UploadRejected('empty');
        }
        if ($size > (int) config('documents.max_size_kb') * 1024) {
            throw new UploadRejected('too_large');
        }

        $displayName = self::displayName($clientName);
        $extension = self::lastExtension($displayName);
        $allowed = array_map('strtolower', config('documents.allowed_extensions'));

        if ($extension === '' || ! in_array($extension, $allowed, true) || ! isset(self::EXTENSION_TYPES[$extension])) {
            throw new UploadRejected('extension_not_allowed');
        }

        $type = $this->detectType($path);

        if (self::EXTENSION_TYPES[$extension] !== $type) {
            throw new UploadRejected('type_mismatch');
        }

        return new InspectedFile($type, self::MIMES[$type], $extension, self::withFallback($displayName, $extension), $size);
    }

    /** Lowercase extension after the LAST dot ("a.pdf.exe" gives "exe"). */
    public static function lastExtension(string $name): string
    {
        $dot = strrpos($name, '.');

        return $dot === false ? '' : strtolower(substr($name, $dot + 1));
    }

    /**
     * Strips any path, control characters and trailing dots/spaces, collapses
     * whitespace and limits to 150 characters.
     */
    public static function displayName(string $clientName): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $clientName) ?? '';
        $name = preg_replace('#^.*[/\\\\]#', '', $name) ?? '';  // keep what follows the last / or \
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = rtrim(trim($name), '. ');

        if (mb_strlen($name) <= 150) {
            return $name;
        }

        // Shorten the stem, keeping a short extension so the type check still sees it.
        $dot = mb_strrpos($name, '.');
        $extension = $dot === false ? '' : mb_substr($name, $dot);

        return mb_strlen($extension) <= 10
            ? mb_substr($name, 0, 150 - mb_strlen($extension)).$extension
            : mb_substr($name, 0, 150);
    }

    private static function withFallback(string $displayName, string $extension): string
    {
        $stem = trim(substr($displayName, 0, (int) strrpos($displayName, '.')), '. ');

        return $stem === '' ? "document.{$extension}" : $displayName;
    }

    private function detectType(string $path): string
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);

        return match (true) {
            str_starts_with($head, '%PDF-') => 'pdf',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($head, "PK\x03\x04") => $this->detectOfficeType($path),
            default => throw new UploadRejected('unknown_type'),
        };
    }

    /** DOCX/XLSX are ZIP packages; inspect the central directory without extracting. */
    private function detectOfficeType(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new UploadRejected('not_office_document');
        }

        try {
            if ($zip->numFiles > (int) config('documents.zip_max_entries')) {
                throw new UploadRejected('zip_too_many_entries');
            }

            $names = [];
            $uncompressed = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
                $name = $stat === false ? null : $stat['name'];

                if ($name === null || str_contains($name, "\0") || str_starts_with($name, '/') || str_starts_with($name, '\\')
                    || str_starts_with($name, '../') || str_contains($name, '/../') || preg_match('/^[A-Za-z]:/', $name)) {
                    throw new UploadRejected('unsafe_zip_entry');
                }

                if (strcasecmp(basename($name), 'vbaProject.bin') === 0) {
                    throw new UploadRejected('macro_content');
                }

                $uncompressed += (int) $stat['size'];
                if ($uncompressed > (int) config('documents.zip_max_uncompressed_bytes')) {
                    throw new UploadRejected('zip_too_large');
                }

                $names[$name] = true;
            }
        } finally {
            $zip->close();
        }

        if (! isset($names['[Content_Types].xml'])) {
            throw new UploadRejected('not_office_document');
        }

        return match (true) {
            isset($names['word/document.xml']) => 'docx',
            isset($names['xl/workbook.xml']) => 'xlsx',
            default => throw new UploadRejected('not_office_document'),
        };
    }
}
