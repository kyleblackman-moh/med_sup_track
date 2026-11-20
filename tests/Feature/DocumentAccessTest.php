<?php

use App\Documents\DocumentDownloadLinks;
use App\Enums\ScanStatus;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Submissions\SubmissionReadiness;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

// Slice 8c: who sees which documents, signed downloads, readiness and props.

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

const FORBIDDEN_DOCUMENT_KEYS = [
    'storage_key', 'sha256', 'scan_detail', 'scan_attempts', 'uploaded_by',
    'withdrawn_at', 'withdrawn_by', 'withdrawn_reason', 'scan_status', 'mime',
];

function editProps(TestResponse $response): array
{
    return $response->assertOk()->viewData('page')['props'];
}

/** Document ids listed in the documents prop, all purposes. */
function listedDocumentIds(array $props): array
{
    return collect($props['documents'])->flatMap(fn ($p) => collect($p['documents'])->pluck('id'))->values()->all();
}

function listedDocument(array $props, string $id): ?array
{
    return collect($props['documents'])->flatMap(fn ($p) => $p['documents'])->firstWhere('id', $id);
}

function expectNoDocumentSecrets(array $props, array $secrets = []): void
{
    $keys = [];
    $walk = function (array $node) use (&$walk, &$keys) {
        foreach ($node as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($props['documents']);

    foreach (FORBIDDEN_DOCUMENT_KEYS as $key) {
        expect($keys)->not->toContain($key);
    }
    foreach ($secrets as $secret) {
        expect(json_encode($props))->not->toContain($secret);
    }
}

// 7. Visibility -----------------------------------------------------------------

it('lists a pending document only to its uploader until it is clean', function () {
    [$case, $uploader] = newDraft('AD_HOC_TENDER_UNABLE');
    $colleague = otherVendorInOrg($uploader);
    $document = makeDocument($case, 'bids', ['scan_status' => ScanStatus::PENDING, 'released_at' => null, 'uploaded_by' => $uploader->id]);

    $mine = editProps($this->actingAs($uploader)->get("/requests/{$case->id}/edit"));
    expect(listedDocument($mine, $document->id))->toMatchArray(['status_label' => 'Scanning', 'can_download' => false])
        ->and(listedDocumentIds(editProps($this->actingAs($colleague)->get("/requests/{$case->id}/edit"))))->toBe([]);

    DB::table('documents')->where('id', $document->id)->update(['scan_status' => 'CLEAN', 'released_at' => now()]);

    $theirs = editProps($this->actingAs($colleague)->get("/requests/{$case->id}/edit"));
    expect(listedDocument($theirs, $document->id))->toMatchArray(['status_label' => 'Ready', 'can_download' => true]);
});

it('shows an infected document only to its uploader, as Rejected', function () {
    [$case, $uploader] = newDraft('AD_HOC_TENDER_UNABLE');
    $document = makeDocument($case, 'bids', [
        'scan_status' => ScanStatus::INFECTED, 'scan_detail' => 'Eicar-Test-Signature', 'released_at' => null, 'uploaded_by' => $uploader->id,
    ]);

    $mine = editProps($this->actingAs($uploader)->get("/requests/{$case->id}/edit"));
    expect(listedDocument($mine, $document->id))->toMatchArray(['status_label' => 'Rejected', 'can_download' => false, 'can_replace' => true])
        ->and(listedDocumentIds(editProps($this->actingAs(otherVendorInOrg($uploader))->get("/requests/{$case->id}/edit"))))->toBe([]);

    expectNoDocumentSecrets($mine, ['Eicar-Test-Signature', $document->storage_key, $document->sha256]);
});

it('hides withdrawn and superseded documents from the lists', function () {
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');
    $withdrawn = makeDocument($case, 'bids', ['withdrawn_at' => now(), 'withdrawn_by' => $vendor->id, 'withdrawn_reason' => 'Wrong']);
    $old = makeDocument($case, 'regulatory');
    $new = makeDocument($case, 'regulatory', ['version' => 2, 'supersedes_id' => $old->id]);

    $props = editProps($this->actingAs($vendor)->get("/requests/{$case->id}/edit"));

    expect(listedDocumentIds($props))->toBe([$new->id]);
    expectNoDocumentSecrets($props, ['Wrong']);
});

// Slice F1: AD_HOC has three categories (was seven purposes for AD_HOC_PHARM).
it('lists the AD_HOC categories with label, hint and required flag', function () {
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');

    $props = editProps($this->actingAs($vendor)->get("/requests/{$case->id}/edit"));

    expect(collect($props['documents'])->map(fn ($g) => [$g['purpose'], $g['label'], $g['is_required'], $g['read_only']])->all())->toBe([
        ['bids', 'Bids', true, false],
        ['regulatory', 'Regulatory Documents', false, false],
        ['evidence', 'Evidence', false, false],
    ]);
    foreach ($props['documents'] as $group) {
        expect($group['hint'])->toBeString()->not->toBe('');
    }
});

it('shows documents of a category the type no longer uses as read-only earlier uploads', function () {
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');
    $earlier = makeDocument($case, 'original_rfq', ['uploaded_by' => $vendor->id]);
    $bids = makeDocument($case, 'bids', ['uploaded_by' => $vendor->id]);

    $groups = collect(editProps($this->actingAs($vendor)->get("/requests/{$case->id}/edit"))['documents'])->keyBy('purpose');

    expect($groups->keys()->all())->toBe(['bids', 'regulatory', 'evidence', 'earlier'])
        ->and($groups['earlier'])->toMatchArray(['label' => 'Earlier Uploads', 'is_required' => false, 'read_only' => true])
        ->and(collect($groups['earlier']['documents'])->pluck('id')->all())->toBe([$earlier->id])
        ->and($groups['earlier']['documents'][0])->toMatchArray(['can_download' => true, 'can_replace' => false, 'can_withdraw' => false])
        ->and($groups['bids']['documents'][0])->toMatchArray(['id' => $bids->id, 'can_replace' => true, 'can_withdraw' => true]);
});

it('shows documents read-only on the show page', function () {
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');
    $document = makeDocument($case, 'bids');

    $response = $this->actingAs($vendor)->get("/requests/{$case->id}")->assertOk();
    $props = $response->viewData('page')['props'];

    expect(listedDocument($props, $document->id))->toMatchArray(['can_download' => true]);
    expectNoDocumentSecrets($props, [$document->storage_key, $document->sha256]);
});

it('gives another organisation 404 on every document URL', function () {
    [$case] = newDraft('AD_HOC_TENDER_UNABLE');
    $document = makeDocument($case, 'bids');
    $outsider = vendorUser(vendorOrg());

    $this->actingAs($outsider)->get("/documents/{$document->id}/download")->assertNotFound();
    $this->actingAs($outsider)->post("/requests/{$case->id}/documents/{$document->id}/withdraw", ['reason' => 'x'])->assertNotFound();

    $url = app(DocumentDownloadLinks::class)->issue($document, Document::withoutGlobalScopes()->find($document->id)->uploader);
    $this->actingAs($outsider)->get($url)->assertNotFound();
});

// 10. Downloads -----------------------------------------------------------------

it('audits the download, then redirects to a short-lived signed link', function () {
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');
    $document = makeDocument($case, 'bids');
    $this->freezeTime();

    $response = $this->actingAs($vendor)->get("/documents/{$document->id}/download");

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);

    expect(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'document.downloaded')->count())->toBe(1)
        ->and($location)->toContain("/documents/{$document->id}/file")
        ->and((int) $query['expires'])->toBe(now()->addSeconds(config('documents.download_link_ttl_seconds'))->getTimestamp())
        ->and((int) $query['user'])->toBe($vendor->id);
});

it('streams the exact bytes with safe headers', function () {
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');
    $bytes = pngBytes();
    $document = makeDocument($case, 'bids', ['_bytes' => $bytes, 'mime' => 'image/png', 'original_name' => 'scan.pdf']);

    $url = app(DocumentDownloadLinks::class)->issue($document, $vendor);
    $response = $this->actingAs($vendor)->get($url)->assertOk();

    expect($response->streamedContent())->toBe($bytes)
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('private');
});

it('rejects a tampered signature, an expired link and another user', function () {
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');
    $document = makeDocument($case, 'bids');
    $url = app(DocumentDownloadLinks::class)->issue($document, $vendor);

    $this->actingAs($vendor)->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url))->assertForbidden();
    $this->actingAs(otherVendorInOrg($vendor))->get($url)->assertForbidden();

    $this->travel(config('documents.download_link_ttl_seconds') + 1)->seconds();
    $this->actingAs($vendor)->get($url)->assertForbidden();
});

it('redirects a guest to login on both routes', function () {
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');
    $document = makeDocument($case, 'bids');
    $url = app(DocumentDownloadLinks::class)->issue($document, $vendor);

    $this->get("/documents/{$document->id}/download")->assertRedirect('/login');
    $this->get($url)->assertRedirect('/login');
});

it('refuses to serve a document that is not clean or is withdrawn', function (array $state) {
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');
    $document = makeDocument($case, 'bids');
    $url = app(DocumentDownloadLinks::class)->issue($document, $vendor);

    DB::table('documents')->where('id', $document->id)->update($state);

    $this->actingAs($vendor)->get("/documents/{$document->id}/download")->assertNotFound();
    $this->actingAs($vendor)->get($url)->assertNotFound();
    expect(fn () => app(DocumentDownloadLinks::class)->issue($document->fresh(), $vendor))->toThrow(Exception::class);
})->with([
    'pending' => [['scan_status' => 'PENDING']],
    'infected' => [['scan_status' => 'INFECTED']],
    'error' => [['scan_status' => 'ERROR']],
    'withdrawn' => [['withdrawn_at' => '2026-11-01 00:00:00']],
]);

it('returns 404 for a nonexistent document', function () {
    $this->actingAs(vendorUser(vendorOrg()))->get('/documents/'.Str::lower((string) Str::ulid()).'/download')->assertNotFound();
});

// 11. Readiness -----------------------------------------------------------------

function documentErrors(ProcurementCase $case): array
{
    $report = app(SubmissionReadiness::class)->evaluate($case);

    return collect($report->errors)->filter(fn ($e) => str_starts_with($e['key'], 'documents.'))
        ->mapWithKeys(fn ($e) => [$e['key'] => $e['message']])->all();
}

// Slice F1: AD_HOC requires one category (Bids) instead of six; the scan-state
// rules at submit are unchanged. Counts changed on purpose (6/5 -> 1/0).
it('reports the required Bids category by state', function () {
    [$case] = newDraft('AD_HOC_TENDER_UNABLE');

    expect(documentErrors($case))->toBe(['documents.bids' => 'Required document missing: Bids']);

    $document = makeDocument($case, 'bids', ['scan_status' => ScanStatus::PENDING, 'released_at' => null]);
    expect(documentErrors($case)['documents.bids'])->toBe('Bids is still being virus-checked. Try again in a moment.'); // wording changed in slice 8d

    DB::table('documents')->where('id', $document->id)->update(['scan_status' => 'INFECTED']);
    expect(documentErrors($case)['documents.bids'])->toBe('Bids failed the security check; upload a replacement');

    DB::table('documents')->where('id', $document->id)->update(['scan_status' => 'ERROR']);
    expect(documentErrors($case)['documents.bids'])->toBe('Bids failed the security check; upload a replacement');

    DB::table('documents')->where('id', $document->id)->update(['scan_status' => 'CLEAN']);
    expect(documentErrors($case))->toBe([]);
});

it('does not require the optional Regulatory and Evidence categories', function () {
    [$case] = newDraft('AD_HOC_TENDER_UNABLE');
    makeDocument($case, 'bids');

    expect(documentErrors($case))->toBe([])
        ->and(requiredPurposes($case))->toBe(['bids']);
});

it('does not count withdrawn or superseded documents', function () {
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');
    makeDocument($case, 'bids', ['withdrawn_at' => now(), 'withdrawn_by' => $vendor->id, 'withdrawn_reason' => null]);
    makeDocument($case, 'regulatory'); // a clean optional file does not stand in for Bids

    expect(documentErrors($case)['documents.bids'])->toStartWith('Required document missing');

    $old = makeDocument($case, 'bids');
    makeDocument($case, 'bids', ['version' => 2, 'supersedes_id' => $old->id, 'scan_status' => ScanStatus::PENDING]);

    expect(documentErrors($case)['documents.bids'])->toEndWith('is still being virus-checked. Try again in a moment.');
});

it('counts only files in the type\'s categories (earlier uploads do not satisfy Bids)', function () {
    [$case] = newDraft('AD_HOC_TENDER_UNABLE');
    makeDocument($case, 'original_rfq');

    expect(documentErrors($case))->toBe(['documents.bids' => 'Required document missing: Bids']);
});

it('is ready with the complete fixture and one clean Bids file', function () {
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');
    saveCompleteAdHoc($case, $vendor);
    attachRequiredDocuments($case);

    $report = app(SubmissionReadiness::class)->evaluate($case);

    expect(documentErrors($case))->toBe([])
        ->and($report->pending)->toBe([])
        ->and($report->isReady())->toBeTrue();
});

// Slice V2-1: no file is required for Tender Excess or Tender Variation.
it('requires no file for Tender Excess and Tender Variation', function (string $code) {
    [$case] = newDraft($code);

    expect(requiredPurposes($case))->toBe([])
        ->and(documentErrors($case))->toBe([]);
})->with(['TENDER_EXCESS', 'TENDER_VARIATION']);
