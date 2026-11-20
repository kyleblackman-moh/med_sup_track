<?php

use App\Documents\Scanning\ClamdScanner;
use App\Documents\Scanning\ScanDocumentJob;
use App\Documents\Scanning\Scanner;
use App\Enums\ScanStatus;
use App\Models\ProcurementCase;
use App\Submissions\SubmissionReadiness;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// SEC-1 (B4, B6): when ClamAV cannot be reached, an upload fails closed: it
// stays unavailable (never CLEAN, never released), nobody can download it, and
// the case does not count it as received, so the request cannot be submitted.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('keeps an upload unavailable and uncounted when ClamAV is unreachable', function () {
    // The real clamd client, pointed at a port nothing listens on.
    useScanner(new ClamdScanner('127.0.0.1', closedPort(), 1, 1));

    [$case, $vendor] = newDraft();
    saveCompleteAdHoc($case, $vendor);
    $purposes = requiredPurposes($case);
    foreach (array_slice($purposes, 1) as $purpose) {
        makeDocument($case, $purpose);
    }
    $document = makeDocument($case, $purposes[0], ['scan_status' => ScanStatus::PENDING, 'scanned_at' => null, 'released_at' => null]);

    // Every attempt fails; the queue then gives up and calls failed().
    $job = new ScanDocumentJob($document->id);
    $last = null;
    foreach (range(1, $job->tries) as $attempt) {
        try {
            $job->handle(app(Scanner::class));
        } catch (Throwable $e) {
            $last = $e;
        }
        expect(DB::table('documents')->where('id', $document->id)->value('scan_status'))->toBe('PENDING');
    }
    $job->failed($last);

    $row = DB::table('documents')->where('id', $document->id)->sole();
    expect($last)->not->toBeNull()
        ->and($row->scan_status)->toBe('ERROR')
        ->and($row->released_at)->toBeNull();

    // Nobody can download it.
    $status = $this->actingAs($vendor)->get(route('documents.download', $document->id))->status();
    expect($status)->toBeIn([403, 404]);

    // The case does not count it as received: readiness names it, and submit is refused.
    $fresh = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
    $errors = collect(app(SubmissionReadiness::class)->evaluate($fresh)->errors);
    expect($errors->pluck('key'))->toContain("documents.{$purposes[0]}")
        ->and($errors->firstWhere('key', "documents.{$purposes[0]}")['message'])->toContain('failed the security check');

    $this->actingAs($vendor)->post(route('requests.submit', $case));
    expect(ProcurementCase::withoutGlobalScopes()->find($case->id)->status->value)->toBe('DRAFT');
});
