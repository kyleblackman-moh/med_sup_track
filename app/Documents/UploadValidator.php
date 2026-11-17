<?php

namespace App\Documents;

use finfo;
use Illuminate\Http\UploadedFile;

/**
 * Inspects an upload. Never trusts the client's name, extension or mime type.
 *
 * UI-3: new uploads are PDF only, for every document category and request
 * type. A file is a PDF when its name ends in .pdf, its bytes start with the
 * %PDF- signature AND libmagic (fileinfo) detects application/pdf. Documents
 * of other types uploaded before this rule stay stored and downloadable.
 */
final class UploadValidator
{
    public const PDF_MIME = 'application/pdf';

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

        // Three reasons (kept apart for the rejection audit row), one message: only PDF files are accepted.
        if ($extension !== 'pdf' || ! in_array($extension, $allowed, true)) {
            throw new UploadRejected('extension_not_allowed');
        }

        if (! str_starts_with((string) file_get_contents($path, false, null, 0, 5), '%PDF-')) {
            throw new UploadRejected('unknown_type');
        }

        if ((new finfo(FILEINFO_MIME_TYPE))->file($path) !== self::PDF_MIME) {
            throw new UploadRejected('type_mismatch');
        }

        return new InspectedFile('pdf', self::PDF_MIME, 'pdf', self::withFallback($displayName, 'pdf'), $size);
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
}
