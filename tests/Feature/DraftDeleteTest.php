<?php

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Submissions\CaseEraser;
use App\Submissions\Exceptions\DraftNotEditable;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// LIST-1: a vendor deletes its own DRAFT through CaseEraser. DatabaseTransactions.

/** Every table that holds a case's own rows, with the column that points at the case. */
const ERASED_TABLES = [
    'case_purchase_orders', 'case_tender_awards', 'case_quotations',
    'case_stock_expiries', 'case_requisitions', 'documents',
];

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A draft of $code with complete content and child rows in every table its type uses. */
function filledDraft(string $code, ?User $vendor = null): array
{
    [$case, $vendor] = draftOf($code, $vendor);

    match ($code) {
        'AD_HOC' => saveCompleteAdHoc($case, $vendor),
        'TENDER_EXCESS' => saveDraft($case, $vendor, tenderExcessPayload()),
    };

    return [$case, $vendor];
}

/** Row counts per table for one case, plus the case row itself. */
function caseFootprint(string $caseId): array
{
    $counts = ['cases' => DB::table('cases')->where('id', $caseId)->count()];
    foreach (ERASED_TABLES as $table) {
        $counts[$table] = DB::table($table)->where('case_id', $caseId)->count();
    }

    return $counts;
}

/** A document row with no file on the disk (makeDocument without the file). Returns its id. */
function makeDocumentRow(ProcurementCase $case, string $purpose): string
{
    $id = Str::lower((string) Str::ulid());
    DB::table('documents')->insert([
        'id' => $id, 'case_id' => $case->id, 'purpose' => $purpose, 'version' => 1,
        'original_name' => "{$purpose}.pdf", 'storage_key' => "cases/{$case->id}/{$id}",
        'mime' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('0', 64),
        'scan_status' => 'CLEAN', 'visibility' => 'EXTERNAL', 'uploaded_by' => $case->created_by,
        'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function draftDeletedAudits(string $caseId)
{
    return DB::table('audit_logs')->where('action', AuditAction::DraftDeleted->value)->where('case_id', $caseId)->get();
}

// 1. The owner deletes a draft -------------------------------------------------

it('lets the owner delete a draft of every type, children first, and touches nothing else', function (string $code) {
    [$case, $vendor] = filledDraft($code);
    [$sibling] = filledDraft($code, $vendor); // same organisation, must survive
    [$other] = filledDraft($code);            // another organisation, must survive

    $before = caseFootprint($case->id);
    $siblingBefore = caseFootprint($sibling->id);
    $otherBefore = caseFootprint($other->id);
    $auditsBefore = DB::table('audit_logs')->where('case_id', $case->id)->count();

    expect($before['cases'])->toBe(1)
        ->and($before['case_requisitions'])->toBe(1)
        ->and($before['case_tender_awards'])->toBe(2)
        ->and($before['case_purchase_orders'])->toBe(1)
        ->and($before['case_quotations'])->toBeGreaterThan(0);

    $this->actingAs($vendor)->delete(route('requests.destroy', $case->id))
        ->assertRedirect(route('requests.index'))
        ->assertSessionHas('success', 'Draft deleted.');

    expect(array_sum(caseFootprint($case->id)))->toBe(0)
        ->and(caseFootprint($sibling->id))->toBe($siblingBefore)
        ->and(caseFootprint($other->id))->toBe($otherBefore)
        // Audit rows outlive the case: the earlier ones stay, one is added.
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->count())->toBe($auditsBefore + 1);
})->with(['AD_HOC', 'TENDER_EXCESS']);

it('writes one draft.deleted audit row with ids, the type code and counts only', function () {
    [$case, $vendor] = filledDraft('AD_HOC');
    // A document row whose file is already gone: counted, and a missing file is not an error.
    $document = makeDocumentRow($case, 'supplier_quotations');

    $this->actingAs($vendor)->delete(route('requests.destroy', $case->id))->assertRedirect(route('requests.index'));

    expect(DB::table('documents')->where('id', $document)->exists())->toBeFalse();

    $audits = draftDeletedAudits($case->id);
    expect($audits)->toHaveCount(1);

    $row = $audits->first();
    $metadata = json_decode($row->metadata, true);

    expect((int) $row->actor_user_id)->toBe($vendor->id)
        ->and($row->actor_org_id)->toBe($vendor->org_id)
        ->and($row->subject_type)->toBe('case')
        ->and($row->subject_id)->toBe($case->id)
        ->and(array_keys($metadata))->toBe(['case_id', 'procurement_type', 'rows', 'files'])
        ->and($metadata['case_id'])->toBe($case->id)
        ->and($metadata['procurement_type'])->toBe('AD_HOC')
        ->and($metadata['rows'])->toBe([
            'purchase_orders' => 1, 'tender_awards' => 2, 'quotations' => 2,
            'stock_expiries' => 2, 'requisitions' => 1, 'documents' => 1,
        ])
        ->and($metadata['files'])->toBe(1);

    // No typed text of any kind: the description, notes, reasons, supplier names, file names.
    $typed = [
        'Infant feeding tube', 'Neonatal enteral feeding', 'delivered part of the order',
        'Within the expected range', 'No registration on file', 'Supplier A', 'Supplier C', 'PO-1001', 'supplier_quotations.pdf',
    ];
    foreach ($typed as $text) {
        expect(str_contains($row->metadata, $text))->toBeFalse("audit metadata contains \"{$text}\"");
    }
});

it('deletes the documents and their files, including a replaced version, and keeps other cases\' files', function () {
    [$case, $vendor] = filledDraft('AD_HOC');
    $first = makeDocument($case, 'supplier_quotations');
    $second = makeDocument($case, 'supplier_quotations', ['version' => 2, 'supersedes_id' => $first->id]);
    [$sibling] = filledDraft('AD_HOC', $vendor);
    $kept = makeDocument($sibling, 'supplier_quotations');

    expect(documentsDisk()->exists($first->storage_key))->toBeTrue()
        ->and(documentsDisk()->exists($second->storage_key))->toBeTrue();

    $this->actingAs($vendor)->delete(route('requests.destroy', $case->id))->assertRedirect(route('requests.index'));

    expect(DB::table('documents')->where('case_id', $case->id)->exists())->toBeFalse()
        ->and(documentsDisk()->exists($first->storage_key))->toBeFalse()
        ->and(documentsDisk()->exists($second->storage_key))->toBeFalse()
        ->and(documentsDisk()->exists($kept->storage_key))->toBeTrue()
        ->and(DB::table('documents')->where('id', $kept->id)->exists())->toBeTrue()
        ->and(json_decode(draftDeletedAudits($case->id)->first()->metadata, true)['files'])->toBe(2);
});

// 2. Who may delete -------------------------------------------------------------

it('returns 404 to a vendor of another organisation and leaves the draft alone', function () {
    [$case] = filledDraft('AD_HOC');
    $before = caseFootprint($case->id);

    $this->actingAs(vendorUser(vendorOrg()))->delete(route('requests.destroy', $case->id))->assertNotFound();

    expect(caseFootprint($case->id))->toBe($before)
        ->and(draftDeletedAudits($case->id))->toHaveCount(0);
});

it('returns 404 to every staff role for a DRAFT', function (Role $role) {
    [$case] = filledDraft('AD_HOC');
    $before = caseFootprint($case->id);

    $this->actingAs(caseUser($role))->delete(route('requests.destroy', $case->id))->assertNotFound();

    expect(caseFootprint($case->id))->toBe($before);
})->with(array_values(array_filter(Role::cases(), fn (Role $r) => $r !== Role::VendorApplicant)));

it('returns 403 to the owner for every status other than DRAFT', function (CaseStatus $status) {
    $vendor = vendorUser(vendorOrg());
    $case = makeCase([
        'org_id' => $vendor->org_id,
        'created_by' => $vendor->id,
        'status' => $status,
        'status_changed_at' => CarbonImmutable::now('UTC'),
    ]);

    $this->actingAs($vendor)->delete(route('requests.destroy', $case->id))->assertForbidden();

    expect(DB::table('cases')->where('id', $case->id)->exists())->toBeTrue()
        ->and(draftDeletedAudits($case->id))->toHaveCount(0);
})->with(array_values(array_filter(CaseStatus::cases(), fn (CaseStatus $s) => $s !== CaseStatus::DRAFT)));

it('redirects a guest to sign in and deletes nothing', function () {
    [$case] = filledDraft('AD_HOC');

    $this->delete(route('requests.destroy', $case->id))->assertRedirect('/login');

    expect(DB::table('cases')->where('id', $case->id)->exists())->toBeTrue();
});

it('throttles deletes at 20 a minute', function () {
    expect(Route::getRoutes()->getByName('requests.destroy')->gatherMiddleware())->toContain('throttle:20,1');
});

// 3. Double submit --------------------------------------------------------------

it('answers a second delete of the same draft gracefully', function () {
    [$case, $vendor] = filledDraft('AD_HOC');

    $this->actingAs($vendor)->delete(route('requests.destroy', $case->id))->assertRedirect(route('requests.index'));
    $this->actingAs($vendor)->delete(route('requests.destroy', $case->id))
        ->assertRedirect(route('requests.index'))
        ->assertSessionHas('success', 'Draft already deleted.');

    expect(draftDeletedAudits($case->id))->toHaveCount(1);
});

it('keeps a deleted draft and an unknown id at 404 for anyone outside the organisation', function () {
    [$case, $vendor] = filledDraft('AD_HOC');
    $this->actingAs($vendor)->delete(route('requests.destroy', $case->id));

    $this->actingAs(vendorUser(vendorOrg()))->delete(route('requests.destroy', $case->id))->assertNotFound();
    $this->actingAs(caseUser(Role::Cmo))->delete(route('requests.destroy', $case->id))->assertNotFound();
    $this->actingAs($vendor)->delete(route('requests.destroy', Str::lower((string) Str::ulid())))->assertNotFound();
});

// 4. The eraser itself -----------------------------------------------------------

it('refuses to erase a case that has history rows, and deletes nothing', function () {
    $vendor = vendorUser(vendorOrg());
    $case = makeCase(['org_id' => $vendor->org_id, 'created_by' => $vendor->id]);
    DB::table('case_transitions')->insert([
        'case_id' => $case->id, 'from_status' => 'DRAFT', 'to_status' => 'SUBMITTED',
        'actor_id' => $vendor->id, 'created_at' => CarbonImmutable::now('UTC'),
    ]);

    expect(fn () => app(CaseEraser::class)->erase($case->id))->toThrow(LogicException::class);

    expect(DB::table('cases')->where('id', $case->id)->exists())->toBeTrue()
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->count())->toBe(1);
});

it('refuses a case that is no longer a draft when it is locked', function () {
    [$case, $vendor] = filledDraft('AD_HOC');
    $stale = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
    // Submitted by another request after this one loaded the case.
    DB::table('cases')->where('id', $case->id)->update(['status' => CaseStatus::SUBMITTED->value]);

    expect(fn () => app(CaseEraser::class)->eraseDraft($stale, $vendor))
        ->toThrow(DraftNotEditable::class);

    expect(DB::table('cases')->where('id', $case->id)->exists())->toBeTrue();
});

// 5. The Requests list -----------------------------------------------------------

it('offers delete only on DRAFT rows of the Requests list', function () {
    [$draft, $vendor] = filledDraft('AD_HOC');
    $submitted = makeCase([
        'org_id' => $vendor->org_id, 'created_by' => $vendor->id,
        'status' => CaseStatus::SUBMITTED, 'status_changed_at' => CarbonImmutable::now('UTC'),
    ]);

    $rows = collect(pageProps($this->actingAs($vendor)->get(route('requests.index'))->assertOk())['requests']['data'])->keyBy('id');

    expect($rows[$draft->id]['can']['delete'])->toBeTrue()
        ->and($rows[$submitted->id]['can']['delete'])->toBeFalse();
});
