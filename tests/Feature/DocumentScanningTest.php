<?php

use App\Documents\DocumentUploadService;
use App\Documents\Scanning\ClamdScanner;
use App\Documents\Scanning\EicarDevScanner;
use App\Documents\Scanning\ScanDocumentJob;
use App\Documents\Scanning\Scanner;
use App\Documents\Scanning\ScanResult;
use App\Enums\DocumentPurpose;
use App\Enums\ScanStatus;
use App\Events\DocumentQuarantined;
use App\Models\Document;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// Slice 8c: ScanDocumentJob, ClamdScanner (against a throw-away TCP server in a
// separate PHP process), EicarDevScanner and the scanner binding.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function pendingDocument(): Document
{
    [$case] = newDraft('AD_HOC_TENDER_UNABLE');

    return makeDocument($case, 'bids', ['scan_status' => ScanStatus::PENDING, 'scanned_at' => null, 'released_at' => null]);
}

function runScan(string $documentId): void
{
    (new ScanDocumentJob($documentId))->handle(app(Scanner::class));
}

function caseAudits(string $caseId): array
{
    return DB::table('audit_logs')->where('case_id', $caseId)->where('action', 'like', 'document.%')
        ->orderBy('id')->pluck('action')->all();
}

// 4. Job ------------------------------------------------------------------------

it('releases a clean document', function () {
    useScanner(new FakeScanner(ScanResult::clean()));
    $document = pendingDocument();

    runScan($document->id);

    $row = DB::table('documents')->where('id', $document->id)->sole();
    expect($row->scan_status)->toBe('CLEAN')
        ->and($row->scanned_at)->not->toBeNull()
        ->and($row->released_at)->not->toBeNull()
        ->and((int) $row->scan_attempts)->toBe(1)
        ->and(caseAudits($document->case_id))->toBe(['document.scan_clean', 'document.released']);
});

it('quarantines an infected document', function () {
    Event::fake([DocumentQuarantined::class]);
    useScanner(new FakeScanner(ScanResult::infected('Eicar-Test-Signature')));
    $document = pendingDocument();
    $originalKey = $document->storage_key;

    runScan($document->id);

    $row = DB::table('documents')->where('id', $document->id)->sole();
    expect($row->scan_status)->toBe('INFECTED')
        ->and($row->scan_detail)->toBe('Eicar-Test-Signature')
        ->and($row->released_at)->toBeNull()
        ->and($row->storage_key)->toStartWith('quarantine/')
        ->and(documentsDisk()->exists($row->storage_key))->toBeTrue()
        ->and(documentsDisk()->exists($originalKey))->toBeFalse()
        ->and(caseAudits($document->case_id))->toBe(['document.scan_infected']);

    $audit = DB::table('audit_logs')->where('case_id', $document->case_id)->where('action', 'document.scan_infected')->sole();
    expect(json_decode($audit->metadata, true)['outcome'])->toBe('failed');
    Event::assertDispatched(DocumentQuarantined::class, fn ($e) => $e->documentId === $document->id);
});

it('retries scanner errors and ends in ERROR, never CLEAN', function () {
    useScanner(new FakeScanner(ScanResult::error('connection refused')));
    $document = pendingDocument();
    $job = new ScanDocumentJob($document->id);

    expect($job->tries)->toBe(3)->and($job->backoff)->toBe([30, 120]);

    foreach (range(1, 3) as $attempt) {
        try {
            $job->handle(app(Scanner::class));
            $this->fail('scan error did not throw');
        } catch (Throwable $e) {
            expect(DB::table('documents')->where('id', $document->id)->value('scan_status'))->toBe('PENDING')
                ->and((int) DB::table('documents')->where('id', $document->id)->value('scan_attempts'))->toBe($attempt);
        }
    }

    $job->failed($e);

    $row = DB::table('documents')->where('id', $document->id)->sole();
    expect($row->scan_status)->toBe('ERROR')
        ->and($row->released_at)->toBeNull()
        ->and($row->scan_detail)->toBeString()->not->toBe('')
        ->and(strlen($row->scan_detail))->toBeLessThanOrEqual(255)
        ->and(caseAudits($document->case_id))->toBe(['document.scan_error']);
});

it('treats a scanner exception as an error, never CLEAN', function () {
    useScanner(new FakeScanner(new RuntimeException('socket blew up')));
    $document = pendingDocument();

    expect(fn () => runScan($document->id))->toThrow(RuntimeException::class, 'socket blew up');
    expect(DB::table('documents')->where('id', $document->id)->value('scan_status'))->toBe('PENDING');
});

it('does nothing for a document that is no longer PENDING', function (ScanStatus $status) {
    $scanner = useScanner(new FakeScanner(ScanResult::clean()));
    [$case] = newDraft('AD_HOC_TENDER_UNABLE');
    $document = makeDocument($case, 'bids', ['scan_status' => $status]);

    runScan($document->id);

    expect($scanner->scanned)->toBe([])
        ->and(DB::table('documents')->where('id', $document->id)->value('scan_status'))->toBe($status->value)
        ->and(caseAudits($case->id))->toBe([]);
})->with([ScanStatus::CLEAN, ScanStatus::INFECTED, ScanStatus::ERROR]);

it('runs end to end through the queue with the dev scanner', function () {
    config(['documents.scanner' => 'eicar_dev']);
    app()->forgetInstance(Scanner::class);
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');

    $clean = app(DocumentUploadService::class)->store($case, $vendor, DocumentPurpose::bids, upload('a.pdf', pdfBytes()));
    $infected = app(DocumentUploadService::class)->store($case, $vendor, DocumentPurpose::regulatory, upload('b.pdf', pdfBytes().EICAR));

    expect(DB::table('documents')->where('id', $clean->id)->value('scan_status'))->toBe('CLEAN')
        ->and(DB::table('documents')->where('id', $infected->id)->value('scan_status'))->toBe('INFECTED');
});

// 5. ClamdScanner -----------------------------------------------------------------

function clamdScanner(int $port, int $chunkSize = 8192, int $readTimeout = 5): ClamdScanner
{
    return new ClamdScanner('127.0.0.1', $port, connectTimeout: 2, readTimeout: $readTimeout, chunkSize: $chunkSize);
}

function scanFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'scan');
    file_put_contents($path, $contents);

    return $path;
}

it('interprets clamd replies', function (string $reply, string $state, ?string $detail) {
    $server = startFakeClamd($reply);

    $result = clamdScanner($server[0])->scan(scanFile('hello world'));
    stopFakeClamd($server);

    expect($result->state)->toBe($state);
    if ($detail !== null) {
        expect($result->detail)->toBe($detail);
    }
})->with([
    'clean' => ['stream: OK', 'clean', null],
    'infected' => ['stream: Eicar-Test-Signature FOUND', 'infected', 'Eicar-Test-Signature'],
    'size limit' => ['stream: INSTREAM size limit exceeded. ERROR', 'error', null],
    'garbage' => ['HELLO THERE', 'error', null],
    'empty' => ['', 'error', null],
    'ok with suffix' => ['stream: OK but not really', 'error', null],
]);

it('sends the file as length-prefixed chunks ending with a zero-length chunk', function () {
    $server = startFakeClamd('stream: OK');
    $contents = 'The quick brown fox jumps over the lazy dog';

    clamdScanner($server[0], chunkSize: 10)->scan(scanFile($contents));
    $received = stopFakeClamd($server);

    expect(substr($received, 0, 10))->toBe("zINSTREAM\0");

    $offset = 10;
    $payload = '';
    $chunks = 0;
    while (true) {
        $length = unpack('N', substr($received, $offset, 4))[1];
        $offset += 4;
        if ($length === 0) {
            break;
        }
        expect($length)->toBeLessThanOrEqual(10);
        $payload .= substr($received, $offset, $length);
        $offset += $length;
        $chunks++;
    }

    expect($payload)->toBe($contents)
        ->and($chunks)->toBe(5)
        ->and($offset)->toBe(strlen($received));
});

it('reports an error when the connection is refused', function () {
    expect(clamdScanner(closedPort())->scan(scanFile('x'))->state)->toBe('error');
});

it('reports an error on a read timeout', function () {
    $server = startFakeClamd('', 'sleep');

    $result = clamdScanner($server[0], readTimeout: 1)->scan(scanFile('x'));
    stopFakeClamd($server);

    expect($result->state)->toBe('error');
});

it('reports an error when the server closes without replying', function () {
    $server = startFakeClamd('', 'close');

    $result = clamdScanner($server[0])->scan(scanFile('x'));
    stopFakeClamd($server);

    expect($result->state)->toBe('error');
});

// 6. Dev scanner and binding ------------------------------------------------------

it('flags the EICAR string and passes other content', function () {
    $scanner = new EicarDevScanner;

    expect($scanner->scan(scanFile('prefix '.EICAR.' suffix'))->state)->toBe('infected')
        ->and($scanner->scan(scanFile(pdfBytes()))->state)->toBe('clean');
});

it('refuses to construct the dev scanner outside local and testing', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => new EicarDevScanner)->toThrow(RuntimeException::class);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('binds the scanner from config and rejects unknown values', function () {
    config(['documents.scanner' => 'clamd']);
    app()->forgetInstance(Scanner::class);
    expect(app(Scanner::class))->toBeInstanceOf(ClamdScanner::class);

    config(['documents.scanner' => 'eicar_dev']);
    app()->forgetInstance(Scanner::class);
    expect(app(Scanner::class))->toBeInstanceOf(EicarDevScanner::class);

    config(['documents.scanner' => 'antivirus-x']);
    app()->forgetInstance(Scanner::class);
    expect(fn () => app(Scanner::class))->toThrow(InvalidArgumentException::class);
});
