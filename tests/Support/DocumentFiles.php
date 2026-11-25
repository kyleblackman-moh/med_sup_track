<?php

// Document helpers for the slice 8c tests. Loaded from tests/Pest.php.
// Minimal VALID file bytes per type, a fake scanner, row builders and a launcher
// for the throw-away clamd server (tests/Support/fake_clamd.php).

use App\Documents\Scanning\Scanner;
use App\Documents\Scanning\ScanResult;
use App\Enums\DocumentPurpose;
use App\Enums\DocumentVisibility;
use App\Enums\ScanStatus;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\ProcurementTypeDocument;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

function pdfBytes(): string
{
    return onePagePdfBytes('Document');
}

/** A one-page PDF that qpdf can open, showing $text (for the letter's appended pages). */
function onePagePdfBytes(string $text = 'Attachment'): string
{
    $content = "BT /F1 24 Tf 72 700 Td ({$text}) Tj ET";

    return "%PDF-1.4\n"
        ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        ."2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n"
        ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
        .'4 0 obj<</Length '.strlen($content).">>stream\n{$content}\nendstream\nendobj\n"
        ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
        ."trailer<</Root 1 0 R>>\n%%EOF\n";
}

function pngBytes(): string
{
    // 1x1 transparent PNG.
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

function jpegBytes(): string
{
    return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
}

/**
 * A zip built with ZipArchive. $kind: docx | xlsx | plain. $extra: name => contents.
 */
function zipBytes(string $kind, array $extra = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    if ($kind !== 'plain') {
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships/>');
        $zip->addFromString($kind === 'docx' ? 'word/document.xml' : 'xl/workbook.xml', '<?xml version="1.0"?><root/>');
    } else {
        $zip->addFromString('readme.txt', 'hello');
    }

    foreach ($extra as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

function upload(string $name, string $bytes, ?string $clientMime = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes)->mimeType($clientMime ?? 'application/octet-stream');
}

function documentsDisk(): Filesystem
{
    return Storage::disk('documents');
}

/** Required purposes configured for the case's procurement type. */
function requiredPurposes(ProcurementCase $case): array
{
    return ProcurementTypeDocument::where('procurement_type_id', $case->procurement_type_id)
        ->where('is_required', true)->orderBy('sort_order')->pluck('purpose')
        ->map(fn ($p) => $p instanceof DocumentPurpose ? $p->value : $p)->all();
}

/**
 * A document row (and its file on the fake disk) without going through the
 * upload pipeline. For readiness, visibility and download tests.
 */
function makeDocument(ProcurementCase $case, string $purpose, array $attributes = []): Document
{
    $id = Str::lower((string) Str::ulid());
    $bytes = $attributes['_bytes'] ?? pdfBytes();
    unset($attributes['_bytes']);
    $key = "cases/{$case->id}/{$id}";
    documentsDisk()->put($key, $bytes);

    $document = new Document;
    $document->forceFill([
        'id' => $id,
        'case_id' => $case->id,
        'purpose' => $purpose,
        'version' => 1,
        'original_name' => "{$purpose}.pdf",
        'storage_key' => $key,
        'mime' => 'application/pdf',
        'size_bytes' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'scan_status' => ScanStatus::CLEAN,
        'scanned_at' => now(),
        'released_at' => now(),
        'visibility' => DocumentVisibility::EXTERNAL,
        'uploaded_by' => $case->created_by,
        'uploaded_at' => now(),
        ...$attributes,
    ])->save();

    return $document;
}

/** CLEAN current documents for every required purpose of the case's type. */
function attachRequiredDocuments(ProcurementCase $case): void
{
    foreach (requiredPurposes($case) as $purpose) {
        makeDocument($case, $purpose);
    }
}

/** A scanner returning queued results in order (or throwing a queued Throwable). */
class FakeScanner implements Scanner
{
    /** @var list<ScanResult|Throwable> */
    public array $results;

    public array $scanned = [];

    public function __construct(ScanResult|Throwable ...$results)
    {
        $this->results = $results;
    }

    public function scan(string $absolutePath): ScanResult
    {
        $this->scanned[] = $absolutePath;
        $next = count($this->results) > 1 ? array_shift($this->results) : $this->results[0];

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}

function useScanner(Scanner $scanner): Scanner
{
    app()->instance(Scanner::class, $scanner);

    return $scanner;
}

/**
 * Starts tests/Support/fake_clamd.php in its own PHP process and returns
 * [port, recordFile, process]. $mode: reply | sleep | close.
 */
function startFakeClamd(string $reply, string $mode = 'reply'): array
{
    $record = tempnam(sys_get_temp_dir(), 'clamd');
    $process = proc_open(
        [PHP_BINARY, base_path('tests/Support/fake_clamd.php'), $record, $mode, base64_encode($reply)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $port = (int) trim(fgets($pipes[1]));

    return [$port, $record, $process, $pipes];
}

function stopFakeClamd(array $server): string
{
    [, $record, $process, $pipes] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_terminate($process);
    proc_close($process);
    $received = (string) file_get_contents($record);
    @unlink($record);

    return $received;
}

/** A local port with nothing listening on it. */
function closedPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    return $port;
}

function otherVendorInOrg(User $vendor): User
{
    return vendorUser($vendor->organisation()->first());
}
