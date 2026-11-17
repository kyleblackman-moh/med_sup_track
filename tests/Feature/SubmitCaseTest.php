<?php

use App\Documents\DocumentUploadService;
use App\Documents\Exceptions\UploadNotAllowed;
use App\Documents\WithdrawDocument;
use App\Enums\CaseStatus;
use App\Enums\DocumentPurpose;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Enums\TechnicalStatus;
use App\Models\CaseQuotation;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\Scopes\VisibleToUserScope;
use App\Models\User;
use App\Submissions\Exceptions\DraftNotEditable;
use App\Submissions\Exceptions\SubmissionNotReady;
use App\Submissions\SubmitCase;
use App\Workflow\CaseTransitionService;
use App\Workflow\Exceptions\GateNotPassed;
use Carbon\Carbon;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Slice 8d: SubmitCase, reference numbers, the T1 guard, registry visibility
// and immutability after submission. DatabaseTransactions; dev counters may hold
// real values, so number assertions are relative to the counter at test start.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
    Queue::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Carbon::setTestNow();
});

function submitter(): SubmitCase
{
    return app(SubmitCase::class);
}

function counterFor(int $year): ?int
{
    $value = DB::table('reference_counters')->where('year', $year)->value('last_number');

    return $value === null ? null : (int) $value;
}

function localYear(): int
{
    return (int) now(config('cases.reference_timezone'))->year;
}

function expectedReference(int $year, int $number): string
{
    return sprintf('%s-%d-%06d', config('cases.reference_prefix'), $year, $number);
}

function rawCaseRow(ProcurementCase $case): object
{
    return DB::table('cases')->where('id', $case->id)->first();
}

function activePoolRows(ProcurementCase $case): int
{
    return DB::table('case_assignments')->where('case_id', $case->id)
        ->whereNull('user_id')->whereNull('completed_at')->whereNull('cancelled_at')
        ->where('role', 'cmo_registry')->count();
}

function canSeeCase(?User $user, ProcurementCase $case): bool
{
    $query = ProcurementCase::withoutGlobalScopes()->whereKey($case->id);
    (new VisibleToUserScope)->applyFor($query, $user);

    return $query->exists();
}

function failAuditInserts(): void
{
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs')) {
            throw new RuntimeException('audit store unavailable');
        }
    });
}

// Happy path ------------------------------------------------------------------------

it('submits a ready draft with a reference, one transition and audits', function () {
    [$case, $vendor] = readyDraft();
    $year = localYear();
    $before = counterFor($year) ?? 0;

    $result = submitter()->handle($case, $vendor);

    $row = rawCaseRow($case);
    expect($result->alreadySubmitted)->toBeFalse()
        ->and($row->status)->toBe('SUBMITTED')
        ->and($row->reference_no)->toBe(expectedReference($year, $before + 1))
        ->and($row->reference_no)->toMatch('/^NR-\d{4}-\d{6}$/')
        ->and($row->current_holder_role)->toBe('cmo_registry')
        ->and(counterFor($year))->toBe($before + 1);

    $transition = DB::table('case_transitions')->where('case_id', $case->id)->sole();
    expect($transition->from_status)->toBe('DRAFT')
        ->and($transition->to_status)->toBe('SUBMITTED')
        ->and((int) $transition->actor_id)->toBe($vendor->id)
        ->and(substr($row->submitted_at, 0, 19))->toBe(substr($transition->created_at, 0, 19));

    $actions = DB::table('audit_logs')->where('case_id', $case->id)->orderBy('id')->pluck('action')->all();
    expect($actions)->toContain('case.reference_assigned')
        ->toContain('case.transitioned')
        ->toContain('case.assigned_to_pool');

    $referenceAudit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.reference_assigned')->sole();
    expect(json_decode($referenceAudit->metadata, true))->toBe(['reference_no' => $row->reference_no])
        ->and((int) $referenceAudit->actor_user_id)->toBe($vendor->id);
});

it('creates exactly one active registry pool assignment', function () {
    [$case, $vendor] = readyDraft();

    submitter()->handle($case, $vendor);

    $assignment = DB::table('case_assignments')->where('case_id', $case->id)->sole();
    expect($assignment->role)->toBe('cmo_registry')
        ->and($assignment->user_id)->toBeNull()
        ->and($assignment->completed_at)->toBeNull()
        ->and($assignment->cancelled_at)->toBeNull();
});

it('allocates sequential numbers', function () {
    $year = localYear();
    $before = counterFor($year) ?? 0;
    $references = [];

    foreach (range(1, 3) as $ignored) {
        [$case, $vendor] = readyDraft();
        submitter()->handle($case, $vendor);
        $references[] = rawCaseRow($case)->reference_no;
    }

    expect($references)->toBe([
        expectedReference($year, $before + 1),
        expectedReference($year, $before + 2),
        expectedReference($year, $before + 3),
    ]);
});

it('keeps a separate counter per year in Trinidad time', function () {
    $tz = config('cases.reference_timezone');
    $before2026 = counterFor(2026) ?? 0;
    $before2027 = counterFor(2027) ?? 0;

    Carbon::setTestNow(Carbon::parse('2026-12-31 23:30', $tz)); // already 2027 in UTC
    [$late, $vendor] = readyDraft();
    submitter()->handle($late, $vendor);

    Carbon::setTestNow(Carbon::parse('2027-01-01 00:30', $tz));
    [$early, $vendor2] = readyDraft();
    submitter()->handle($early, $vendor2);

    expect(rawCaseRow($late)->reference_no)->toBe(expectedReference(2026, $before2026 + 1))
        ->and(rawCaseRow($early)->reference_no)->toBe(expectedReference(2027, $before2027 + 1));
});

// Blocked submissions -----------------------------------------------------------------

it('blocks an incomplete draft and changes nothing', function () {
    [$case, $vendor] = newDraft();
    $before = counterFor(localYear());

    try {
        submitter()->handle($case, $vendor);
        $this->fail('submitted');
    } catch (SubmissionNotReady $e) {
        expect(collect($e->report->errors)->pluck('key'))->toContain('requisition.item_description')
            ->toContain('documents.bids');
    }

    $row = rawCaseRow($case);
    expect($row->status)->toBe('DRAFT')
        ->and($row->reference_no)->toBeNull()
        ->and($row->submitted_at)->toBeNull()
        ->and(counterFor(localYear()))->toBe($before)
        ->and(activePoolRows($case))->toBe(0)
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->count())->toBe(0);
});

it('blocks while a required document is still being virus-checked', function () {
    [$case, $vendor] = readyDraft();
    Document::withoutGlobalScopes()->where('case_id', $case->id)->where('purpose', 'bids')
        ->update(['scan_status' => 'PENDING']);

    try {
        submitter()->handle($case, $vendor);
        $this->fail('submitted');
    } catch (SubmissionNotReady $e) {
        expect($e->report->errors)->toBe([[
            'key' => 'documents.bids',
            'message' => 'Bids is still being virus-checked. Try again in a moment.',
        ]]);
    }
    expect(rawCaseRow($case)->status)->toBe('DRAFT');
});

it('blocks when a required document failed the security check', function (ScanStatus $status) {
    [$case, $vendor] = readyDraft();
    Document::withoutGlobalScopes()->where('case_id', $case->id)->where('purpose', 'bids')
        ->update(['scan_status' => $status->value]);

    expect(fn () => submitter()->handle($case, $vendor))->toThrow(SubmissionNotReady::class);
    expect(rawCaseRow($case)->status)->toBe('DRAFT')->and(rawCaseRow($case)->reference_no)->toBeNull();
})->with([ScanStatus::INFECTED, ScanStatus::ERROR]);

it('does not let warnings block submission (AD_HOC)', function () {
    [$case, $vendor] = readyDraft();
    CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->where('sort_order', 0)->firstOrFail()
        ->forceFill(['technical_status' => TechnicalStatus::TECHNICALLY_REJECTED])->save();

    submitter()->handle($case, $vendor);

    expect(rawCaseRow($case)->status)->toBe('SUBMITTED');
});

// Slice F1: old-type drafts made before F1 can still be submitted under their rules.
it('does not let warnings block submission (old type)', function () {
    [$case, $vendor] = legacyDraft('AD_HOC_NON_PHARM');
    $payload = nonPharmPayload();
    $payload['requisition']['purpose'] = null; // warning only
    saveDraft($case, $vendor, $payload);
    attachRequiredDocuments($case);

    submitter()->handle($case, $vendor);

    expect(rawCaseRow($case)->status)->toBe('SUBMITTED');
});

// Idempotency and atomicity -----------------------------------------------------------

it('returns AlreadySubmitted for a stale second instance', function () {
    [$case, $vendor] = readyDraft();
    $stale = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);

    submitter()->handle($case, $vendor);
    $reference = rawCaseRow($case)->reference_no;
    $counter = counterFor(localYear());

    $second = submitter()->handle($stale, $vendor);

    expect($second->alreadySubmitted)->toBeTrue()
        ->and(rawCaseRow($case)->reference_no)->toBe($reference)
        ->and(counterFor(localYear()))->toBe($counter)
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->count())->toBe(1)
        ->and(activePoolRows($case))->toBe(1)
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.reference_assigned')->count())->toBe(1);
});

it('rolls everything back when an audit write fails', function () {
    [$case, $vendor] = readyDraft();
    $before = counterFor(localYear());
    failAuditInserts();

    expect(fn () => submitter()->handle($case, $vendor))->toThrow(RuntimeException::class, 'audit store unavailable');

    $row = rawCaseRow($case);
    expect($row->status)->toBe('DRAFT')
        ->and($row->reference_no)->toBeNull()
        ->and($row->submitted_at)->toBeNull()
        ->and(counterFor(localYear()))->toBe($before)
        ->and(DB::table('case_assignments')->where('case_id', $case->id)->count())->toBe(0)
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->count())->toBe(0);
});

// T1 guard ------------------------------------------------------------------------------

it('refuses a direct T1 transition without a reference', function () {
    [$case, $vendor] = readyDraft();

    try {
        app(CaseTransitionService::class)->transition($case, CaseStatus::SUBMITTED, $vendor);
        $this->fail('reached SUBMITTED');
    } catch (GateNotPassed $e) {
        expect($e->missing)->toBe(['reference_no'])
            ->and($e->getMessage())->toContain('reference_no');
    }
    expect(rawCaseRow($case)->status)->toBe('DRAFT');
});

it('refuses a direct T1 transition with a reference but failing readiness', function () {
    [$case, $vendor] = newDraft();
    DB::table('cases')->where('id', $case->id)->update(['reference_no' => 'NR-1999-'.Str::random(6)]);

    try {
        app(CaseTransitionService::class)->transition($case->fresh() ?? $case, CaseStatus::SUBMITTED, $vendor);
        $this->fail('reached SUBMITTED');
    } catch (GateNotPassed $e) {
        expect($e->missing)->toContain('requisition.item_description')
            ->not->toContain('reference_no');
    }
});

// Schema ------------------------------------------------------------------------------

it('ignores mass assignment of reference_no', function () {
    $case = ProcurementCase::make(['title' => 'X', 'reference_no' => 'NR-2026-999999']);

    expect($case->getAttributes())->not->toHaveKey('reference_no');
});

it('rejects a duplicate reference but allows many nulls', function () {
    $a = makeCase();
    $b = makeCase();
    makeCase();
    $reference = 'NR-1999-'.Str::upper(Str::random(6));

    DB::table('cases')->where('id', $a->id)->update(['reference_no' => $reference]);

    expect(fn () => DB::transaction(fn () => DB::table('cases')->where('id', $b->id)->update(['reference_no' => $reference])))
        ->toThrow(QueryException::class)
        ->and(DB::table('cases')->whereNull('reference_no')->count())->toBeGreaterThanOrEqual(2);
});

// Visibility after submit ---------------------------------------------------------------

it('lets the registry, CMO and PS Office see a submitted case, and nobody else new', function () {
    [$case, $vendor] = readyDraft();
    $registry = caseUser(Role::CmoRegistry);
    expect(canSeeCase($registry, $case))->toBeFalse(); // drafts are not in the registry pool

    submitter()->handle($case, $vendor);

    expect(canSeeCase($registry, $case))->toBeTrue()
        ->and(canSeeCase(caseUser(Role::Cmo), $case))->toBeTrue()
        ->and(canSeeCase(caseUser(Role::PsOffice), $case))->toBeTrue()
        ->and(canSeeCase($vendor, $case))->toBeTrue()
        ->and(canSeeCase(otherVendorInOrg($vendor), $case))->toBeTrue();

    foreach ([Role::Technocrat, Role::CabOfficer, Role::LegalOfficer, Role::SystemAdmin, Role::Auditor] as $role) {
        expect(canSeeCase(caseUser($role), $case))->toBeFalse($role->value);
    }
    expect(canSeeCase(vendorUser(vendorOrg()), $case))->toBeFalse()
        ->and(canSeeCase(null, $case))->toBeFalse();
});

// Immutability after submit -------------------------------------------------------------

it('freezes the request content and documents once submitted', function () {
    [$case, $vendor] = readyDraft();
    $document = Document::withoutGlobalScopes()->where('case_id', $case->id)->where('purpose', 'bids')->firstOrFail();
    submitter()->handle($case, $vendor);
    $case = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);

    expect(fn () => saveDraft($case, $vendor, ['requisition' => ['strength' => 'LATE']]))->toThrow(DraftNotEditable::class)
        ->and(fn () => app(DocumentUploadService::class)->store($case, $vendor, DocumentPurpose::bids, upload('late.pdf', pdfBytes())))
        ->toThrow(UploadNotAllowed::class)
        ->and(fn () => app(DocumentUploadService::class)->store($case, $vendor, DocumentPurpose::bids, upload('late.pdf', pdfBytes()), $document->id))
        ->toThrow(UploadNotAllowed::class)
        ->and(fn () => app(WithdrawDocument::class)->handle($document, $vendor, 'Too late'))
        ->toThrow(UploadNotAllowed::class);

    expect(documentsDisk()->allFiles())->toHaveCount(count(requiredPurposes($case)));
});
