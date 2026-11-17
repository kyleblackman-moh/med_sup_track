<?php

use App\Documents\DocumentUploadService;
use App\Documents\Exceptions\UploadNotAllowed;
use App\Documents\Scanning\ScanDocumentJob;
use App\Documents\WithdrawDocument;
use App\Enums\CaseStatus;
use App\Enums\DocumentPurpose;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Models\Document;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Slice 8c: upload pipeline, limits, versioning, withdrawal, throttles, rescan.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
    Queue::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function uploadService(): DocumentUploadService
{
    return app(DocumentUploadService::class);
}

function storeDocument($case, $user, string $purpose = 'bids', ?string $name = null, ?string $bytes = null, ?string $supersedes = null): Document
{
    return uploadService()->store($case, $user, DocumentPurpose::from($purpose), upload($name ?? 'quote.pdf', $bytes ?? pdfBytes()), $supersedes);
}

function documentAudits(string $caseId, string $action)
{
    return DB::table('audit_logs')->where('case_id', $caseId)->where('action', $action)->get();
}

function storedKeys(): array
{
    return documentsDisk()->allFiles();
}

// 2. Pipeline -------------------------------------------------------------------

it('stores a valid upload as a PENDING external document and queues the scan', function () {
    [$case, $vendor] = newDraft('AD_HOC');

    $document = storeDocument($case, $vendor, 'bids', 'My RFQ.pdf');

    $row = DB::table('documents')->where('id', $document->id)->sole();
    expect($row->scan_status)->toBe('PENDING')
        ->and($row->visibility)->toBe('EXTERNAL')
        ->and((int) $row->version)->toBe(1)
        ->and($row->supersedes_id)->toBeNull()
        ->and($row->original_name)->toBe('My RFQ.pdf')
        ->and($row->mime)->toBe('application/pdf')
        ->and($row->storage_key)->toBe("cases/{$case->id}/{$document->id}")
        ->and($row->storage_key)->not->toContain('RFQ')
        ->and($row->sha256)->toBe(hash('sha256', pdfBytes()))
        ->and((int) $row->size_bytes)->toBe(strlen(pdfBytes()))
        ->and((int) $row->uploaded_by)->toBe($vendor->id)
        ->and(documentsDisk()->get($row->storage_key))->toBe(pdfBytes());

    $audit = documentAudits($case->id, 'document.upload_received')->sole();
    $context = json_decode($audit->metadata, true);
    expect($context)->toBe([
        'document_id' => $document->id, 'purpose' => 'bids', 'version' => 1,
        'size_bytes' => strlen(pdfBytes()), 'sha256' => hash('sha256', pdfBytes()),
    ])->and($audit->metadata)->not->toContain('%PDF')
        ->and($audit->metadata)->not->toContain('My RFQ');

    Queue::assertPushed(ScanDocumentJob::class, fn (ScanDocumentJob $job) => $job->documentId === $document->id);
});

it('never puts the original name in the stored path', function () {
    [$case, $vendor] = newDraft('AD_HOC');

    $document = storeDocument($case, $vendor, 'bids', '../../evil.pdf');

    expect($document->original_name)->toBe('evil.pdf')
        ->and(storedKeys())->toBe(["cases/{$case->id}/{$document->id}"])
        ->and(implode('|', storedKeys()))->not->toContain('evil');
});

it('audits a rejected file and stores nothing', function () {
    [$case, $vendor] = newDraft('AD_HOC');

    expect(fn () => storeDocument($case, $vendor, 'bids', 'setup.pdf', 'MZ executable'))
        ->toThrow(ValidationException::class);

    $audit = documentAudits($case->id, 'document.validation_rejected')->sole();
    expect(json_decode($audit->metadata, true))->toBe([
        'outcome' => 'failed', 'reason' => 'unknown_type', 'declared_extension' => 'pdf', 'size_bytes' => 13,
    ])->and($audit->metadata)->not->toContain('MZ executable')
        ->and(DB::table('documents')->where('case_id', $case->id)->count())->toBe(0)
        ->and(storedKeys())->toBe([]);
    Queue::assertNothingPushed();
});

it('removes the stored file when the audit write fails', function () {
    [$case, $vendor] = newDraft('AD_HOC');
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs')) {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => storeDocument($case, $vendor))->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(DB::table('documents')->where('case_id', $case->id)->count())->toBe(0)
        ->and(storedKeys())->toBe([]);
    Queue::assertNothingPushed();
});

// 3. Authorisation and limits ---------------------------------------------------

it('refuses staff users', function (Role $role) {
    [$case] = newDraft('AD_HOC');

    expect(fn () => storeDocument($case, caseUser($role)))->toThrow(AuthorizationException::class);
    expect(storedKeys())->toBe([]);
})->with([Role::Cmo, Role::Technocrat]);

it('returns 404 to a vendor of another organisation', function () {
    [$case] = newDraft('AD_HOC');

    $this->actingAs(vendorUser(vendorOrg()))
        ->post("/requests/{$case->id}/documents", ['purpose' => 'bids', 'file' => upload('q.pdf', pdfBytes())])
        ->assertNotFound();

    expect(storedKeys())->toBe([]);
});

it('refuses uploads once the case is not a draft', function () {
    $org = vendorOrg();
    $vendor = vendorUser($org);
    $case = makeCase(['org_id' => $org->id, 'status' => CaseStatus::SUBMITTED, 'procurement_type_id' => procurementTypeByCode('AD_HOC')->id]);

    expect(fn () => storeDocument($case, $vendor))->toThrow(UploadNotAllowed::class);
    expect(storedKeys())->toBe([]);
});

it('refuses a purpose not configured for the procurement type', function () {
    [$case, $vendor] = newDraft('AD_HOC');

    try {
        storeDocument($case, $vendor, 'original_rfq');
        $this->fail('accepted');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('purpose');
    }
    expect(storedKeys())->toBe([]);
});

it('enforces the files-per-case cap', function () {
    config(['documents.max_files_per_case' => 2]);
    [$case, $vendor] = newDraft('AD_HOC');
    storeDocument($case, $vendor);
    storeDocument($case, $vendor, 'regulatory');

    try {
        storeDocument($case, $vendor, 'evidence');
        $this->fail('accepted');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('file');
    }
    expect(storedKeys())->toHaveCount(2);
});

it('enforces the bytes-per-case cap', function () {
    config(['documents.max_bytes_per_case' => strlen(pdfBytes()) + 10]);
    [$case, $vendor] = newDraft('AD_HOC');
    storeDocument($case, $vendor);

    expect(fn () => storeDocument($case, $vendor, 'regulatory'))->toThrow(ValidationException::class);
    expect(storedKeys())->toHaveCount(1);
});

// 8. Versioning -----------------------------------------------------------------

it('replaces a current document with a new version', function () {
    [$case, $vendor] = newDraft('AD_HOC');
    $v1 = storeDocument($case, $vendor);

    $v2 = storeDocument($case, $vendor, 'bids', 'new.pdf', supersedes: $v1->id);

    expect($v2->version)->toBe(2)
        ->and($v2->supersedes_id)->toBe($v1->id)
        ->and(Document::withoutGlobalScopes()->current()->where('case_id', $case->id)->pluck('id')->all())->toBe([$v2->id])
        ->and(documentAudits($case->id, 'document.upload_received'))->toHaveCount(2);

    $superseded = documentAudits($case->id, 'document.superseded')->sole();
    expect(json_decode($superseded->metadata, true))->toBe(['document_id' => $v2->id, 'supersedes_id' => $v1->id, 'version' => 2]);
});

it('refuses to replace a non-current, withdrawn, other-case or other-purpose document', function (string $scenario) {
    [$case, $vendor] = newDraft('AD_HOC');
    $v1 = storeDocument($case, $vendor);

    $target = match ($scenario) {
        'non-current' => (function () use ($case, $vendor, $v1) {
            storeDocument($case, $vendor, supersedes: $v1->id);

            return $v1->id;
        })(),
        'withdrawn' => (function () use ($vendor, $v1) {
            app(WithdrawDocument::class)->handle($v1, $vendor, 'Wrong file');

            return $v1->id;
        })(),
        'other case' => storeDocument(newDraft('AD_HOC', $vendor)[0], $vendor)->id,
        'other purpose' => storeDocument($case, $vendor, 'regulatory')->id,
    };

    try {
        storeDocument($case, $vendor, 'bids', supersedes: $target);
        $this->fail('accepted');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('supersedes_id');
    }
})->with(['non-current', 'withdrawn', 'other case', 'other purpose']);

// 9. Withdrawal -----------------------------------------------------------------

it('withdraws a document with a reason and keeps the file', function () {
    [$case, $vendor] = newDraft('AD_HOC');
    $document = storeDocument($case, $vendor);

    app(WithdrawDocument::class)->handle($document, $vendor, '  Uploaded the wrong file  ');

    $row = DB::table('documents')->where('id', $document->id)->sole();
    expect($row->withdrawn_at)->not->toBeNull()
        ->and((int) $row->withdrawn_by)->toBe($vendor->id)
        ->and($row->withdrawn_reason)->toBe('Uploaded the wrong file')
        ->and(documentsDisk()->exists($row->storage_key))->toBeTrue()
        ->and(documentAudits($case->id, 'document.withdrawn'))->toHaveCount(1);
});

// Slice F1: the reason is optional (the drop zones send none). Changed on purpose:
// '' and '   ' used to be refused; they now withdraw with no reason stored.
it('withdraws a document without a reason, keeping the row and the file', function (?string $reason) {
    [$case, $vendor] = newDraft('AD_HOC');
    $document = storeDocument($case, $vendor);

    app(WithdrawDocument::class)->handle($document, $vendor, $reason);

    $row = DB::table('documents')->where('id', $document->id)->sole();
    $audit = documentAudits($case->id, 'document.withdrawn');
    expect($row->withdrawn_at)->not->toBeNull()
        ->and((int) $row->withdrawn_by)->toBe($vendor->id)
        ->and($row->withdrawn_reason)->toBeNull()
        ->and(documentsDisk()->exists($row->storage_key))->toBeTrue()
        ->and($audit)->toHaveCount(1)
        ->and(json_decode($audit[0]->metadata, true))->toBe(['document_id' => $document->id, 'purpose' => 'bids', 'version' => 1]);
})->with([null, '', '   ']);

it('refuses a withdrawal reason over 500 characters', function () {
    [$case, $vendor] = newDraft('AD_HOC');
    $document = storeDocument($case, $vendor);

    expect(fn () => app(WithdrawDocument::class)->handle($document, $vendor, str_repeat('x', 501)))->toThrow(ValidationException::class);
    expect(DB::table('documents')->where('id', $document->id)->value('withdrawn_at'))->toBeNull();
});

it('withdraws through the route with no reason sent', function () {
    [$case, $vendor] = newDraft('AD_HOC');
    $document = storeDocument($case, $vendor);

    $this->actingAs($vendor)->from("/requests/{$case->id}/edit")
        ->post("/requests/{$case->id}/documents/{$document->id}/withdraw")
        ->assertRedirect("/requests/{$case->id}/edit")
        ->assertSessionHas('success', 'Document removed.');

    expect(DB::table('documents')->where('id', $document->id)->value('withdrawn_at'))->not->toBeNull();
});

it('refuses a second withdrawal', function () {
    [$case, $vendor] = newDraft('AD_HOC');
    $document = storeDocument($case, $vendor);
    app(WithdrawDocument::class)->handle($document, $vendor, 'First');

    expect(fn () => app(WithdrawDocument::class)->handle($document->fresh(), $vendor, 'Second'))->toThrow(ValidationException::class);
    expect(documentAudits($case->id, 'document.withdrawn'))->toHaveCount(1);
});

it('refuses withdrawal on a case that is not a draft', function () {
    [$case, $vendor] = newDraft('AD_HOC');
    $document = storeDocument($case, $vendor);
    DB::table('cases')->where('id', $case->id)->update(['status' => 'SUBMITTED']);

    expect(fn () => app(WithdrawDocument::class)->handle($document->fresh(), $vendor, 'Too late'))->toThrow(UploadNotAllowed::class);
});

it('returns 404 when another organisation tries to withdraw', function () {
    [$case, $vendor] = newDraft('AD_HOC');
    $document = storeDocument($case, $vendor);

    $this->actingAs(vendorUser(vendorOrg()))
        ->post("/requests/{$case->id}/documents/{$document->id}/withdraw", ['reason' => 'Mine now'])
        ->assertNotFound();

    expect(DB::table('documents')->where('id', $document->id)->value('withdrawn_at'))->toBeNull();
});

it('withdraws through the route and redirects back', function () {
    [$case, $vendor] = newDraft('AD_HOC');
    $document = storeDocument($case, $vendor);

    $this->actingAs($vendor)->from("/requests/{$case->id}/edit")
        ->post("/requests/{$case->id}/documents/{$document->id}/withdraw", ['reason' => 'Wrong file'])
        ->assertRedirect("/requests/{$case->id}/edit")
        ->assertSessionHas('success');

    expect(DB::table('documents')->where('id', $document->id)->value('withdrawn_reason'))->toBe('Wrong file');
});

it('returns 404 for a document that belongs to a different case', function () {
    [$case, $vendor] = newDraft('AD_HOC');
    [$other] = newDraft('AD_HOC', $vendor);
    $document = storeDocument($other, $vendor);

    $this->actingAs($vendor)
        ->post("/requests/{$case->id}/documents/{$document->id}/withdraw", ['reason' => 'x'])
        ->assertNotFound();
});

// HTTP upload ---------------------------------------------------------------------

it('uploads through the route and redirects back with a flash', function () {
    [$case, $vendor] = newDraft('AD_HOC');

    $this->actingAs($vendor)->from("/requests/{$case->id}/edit")
        ->post("/requests/{$case->id}/documents", [
            'purpose' => 'bids',
            'file' => upload('q.pdf', pdfBytes(), 'application/x-msdownload'),
            'visibility' => 'INTERNAL', 'scan_status' => 'CLEAN',
        ])
        ->assertRedirect("/requests/{$case->id}/edit")
        ->assertSessionHas('success');

    // Client-declared mime, visibility and scan status are ignored.
    $row = DB::table('documents')->where('case_id', $case->id)->sole();
    expect($row->mime)->toBe('application/pdf')
        ->and($row->visibility)->toBe('EXTERNAL')
        ->and($row->scan_status)->toBe('PENDING');
});

it('reports a rejected upload as a validation error on file', function () {
    [$case, $vendor] = newDraft('AD_HOC');

    $this->actingAs($vendor)->post("/requests/{$case->id}/documents", ['purpose' => 'bids', 'file' => upload('x.pdf', 'not a pdf')])
        ->assertSessionHasErrors('file');
    $this->actingAs($vendor)->post("/requests/{$case->id}/documents", ['purpose' => 'not_a_purpose', 'file' => upload('x.pdf', pdfBytes())])
        ->assertSessionHasErrors('purpose');
});

// 12. Throttle and rescan ---------------------------------------------------------

// Slice F1: raised from 20 to 60 a minute (one request per file). Changed on purpose.
it('throttles uploads at 60 a minute per user', function () {
    [$case, $vendor] = newDraft('AD_HOC');

    foreach (range(1, 60) as $i) {
        $this->actingAs($vendor)->post("/requests/{$case->id}/documents", ['purpose' => 'bids', 'file' => upload("q{$i}.pdf", pdfBytes())])
            ->assertRedirect();
    }

    $this->actingAs($vendor)->post("/requests/{$case->id}/documents", ['purpose' => 'bids', 'file' => upload('q61.pdf', pdfBytes())])
        ->assertStatus(429);

    // Per user: another vendor of the same organisation is not throttled.
    $other = otherVendorInOrg($vendor);
    $this->actingAs($other)->post("/requests/{$case->id}/documents", ['purpose' => 'bids', 'file' => upload('o1.pdf', pdfBytes())])
        ->assertRedirect();
});

it('re-queues only PENDING and ERROR documents older than the given minutes', function () {
    [$case] = newDraft('AD_HOC');
    $this->travel(-30)->minutes();
    $oldPending = makeDocument($case, 'bids', ['scan_status' => ScanStatus::PENDING, 'released_at' => null]);
    $oldError = makeDocument($case, 'regulatory', ['scan_status' => ScanStatus::ERROR, 'released_at' => null]);
    $oldClean = makeDocument($case, 'evidence');
    $this->travelBack();
    $newPending = makeDocument($case, 'bids', ['scan_status' => ScanStatus::PENDING, 'released_at' => null]);

    $this->artisan('documents:rescan', ['--minutes' => 15])->expectsOutputToContain('2')->assertSuccessful();

    $queued = [];
    Queue::assertPushed(ScanDocumentJob::class, function (ScanDocumentJob $job) use (&$queued) {
        $queued[] = $job->documentId;

        return true;
    });
    expect(collect($queued)->sort()->values()->all())->toBe(collect([$oldPending->id, $oldError->id])->sort()->values()->all())
        ->and(DB::table('documents')->where('id', $oldError->id)->value('scan_status'))->toBe('PENDING')
        ->and(DB::table('documents')->where('id', $oldClean->id)->value('scan_status'))->toBe('CLEAN')
        ->and($queued)->not->toContain($newPending->id);
});
