<?php

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\Document;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

// Slice 9a: staff case list, read-only case view and staff document download.
// DatabaseTransactions only (tests/Pest.php). The dev database holds other
// rows, so list assertions filter by a per-test organisation.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('documents');
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const STAFF_ROLES_WITH_ACCESS = ['cmo', 'ps_office', 'cmo_registry', 'technocrat', 'cab_officer', 'legal_officer'];

/** A non-draft case, as submission would leave it. */
function staffCase(array $attributes = []): ProcurementCase
{
    $case = makeCase([
        'status' => CaseStatus::SUBMITTED,
        'submitted_at' => now(),
        'reference_no' => 'NR-9A-'.Str::upper(Str::random(8)),
        'current_holder_role' => 'cmo_registry',
        ...$attributes,
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'Item for '.$case->reference_no])->save();

    return $case;
}

/** Ids of the cases on the list page for $user, limited to $org. */
function listedIds(User $user, Organisation $org, array $query = []): array
{
    $props = pageProps(test()->actingAs($user)->get(route('cases.index', ['org' => $org->id, ...$query]))->assertOk());

    return collect($props['cases']['data'])->pluck('id')->all();
}

/** Page props without the shared ones (auth, flash, quote...). */
function ownProps(TestResponse $response): array
{
    return collect(pageProps($response))->except(['auth', 'name', 'quote', 'flash', 'can', 'errors', 'ziggy', 'sidebarOpen'])->all();
}

// 1. Who may use the staff pages -------------------------------------------------

it('lets the six case roles use the staff pages and shares can.cases', function (string $role) {
    $user = caseUser($role);

    $this->actingAs($user)->get(route('cases.index'))->assertOk();
    expect(pageProps($this->actingAs($user)->get('/dashboard'))['can']['cases'])->toBeTrue();
})->with(STAFF_ROLES_WITH_ACCESS);

it('refuses vendors, system_admin, auditor and users without a case permission', function (string $who) {
    $user = match ($who) {
        'vendor' => vendorUser(vendorOrg()),
        'no_role' => caseUser(),
        default => caseUser($who),
    };

    $this->actingAs($user)->get(route('cases.index'))->assertForbidden();
    expect(pageProps($this->actingAs($user)->get('/dashboard'))['can']['cases'])->toBeFalse();
})->with(['vendor', 'system_admin', 'auditor', 'no_role']);

it('refuses a vendor on the staff view of its own submitted case (403)', function () {
    $org = vendorOrg();
    $case = staffCase(['org_id' => $org->id]);

    $this->actingAs(vendorUser($org))->get(route('cases.show', $case))->assertForbidden();
});

it('refuses a vendor even when it holds case.view_all', function () {
    $vendor = vendorUser(vendorOrg());
    $vendor->givePermissionTo(Permission::CaseViewAll->value);

    $this->actingAs($vendor)->get(route('cases.index'))->assertForbidden();
});

it('sends guests to login', function () {
    $this->get(route('cases.index'))->assertRedirect(route('login'));
});

// 2. Scope matrix -----------------------------------------------------------------

/**
 * role x status x assignment -> visible on the list and the view.
 * cmo and ps_office see every non-draft case; assigned roles only with an
 * assignment (active, or completed as read-only); nobody sees a DRAFT.
 */
function expectedStaffVisibility(string $role, CaseStatus $status, string $assignment): bool
{
    return match (true) {
        $status === CaseStatus::DRAFT => false,
        in_array($role, ['cmo', 'ps_office'], true) => true,
        default => $assignment !== 'none',
    };
}

it('shows each staff role exactly the cases its scope allows', function (string $role, CaseStatus $status, string $assignment) {
    $org = vendorOrg();
    $user = caseUser($role);
    $case = staffCase(['org_id' => $org->id, 'status' => $status]);

    if ($assignment !== 'none') {
        assign($case, $role, $user, completed: $assignment === 'completed');
    }

    $visible = expectedStaffVisibility($role, $status, $assignment);

    expect(listedIds($user, $org))->toBe($visible ? [$case->id] : []);
    $this->actingAs($user)->get(route('cases.show', $case))->assertStatus($visible ? 200 : 404);
})->with(STAFF_ROLES_WITH_ACCESS)
    ->with([CaseStatus::SUBMITTED, CaseStatus::CLOSED, CaseStatus::DRAFT])
    ->with(['none', 'active', 'completed']);

it('shows cmo_registry a SUBMITTED case through the active pool row only', function () {
    $org = vendorOrg();
    $registry = caseUser(Role::CmoRegistry);
    $pooled = staffCase(['org_id' => $org->id]);
    $completedPool = staffCase(['org_id' => $org->id, 'status' => CaseStatus::CLOSED]);
    assign($pooled, Role::CmoRegistry);
    assign($completedPool, Role::CmoRegistry, completed: true);

    expect(listedIds($registry, $org))->toBe([$pooled->id]);
});

it('never shows a DRAFT to any staff role, even with an assignment or a pool row', function (string $role) {
    $org = vendorOrg();
    $user = caseUser($role);
    $draft = makeCase(['org_id' => $org->id, 'status' => CaseStatus::DRAFT]);
    assign($draft, $role, $user);
    assign($draft, $role);

    expect(listedIds($user, $org))->toBe([]);
    $this->actingAs($user)->get(route('cases.show', $draft))->assertNotFound();
})->with(STAFF_ROLES_WITH_ACCESS);

it('keeps DRAFT requisitions, child rows and documents out of every staff query', function () {
    $draft = makeCase(['status' => CaseStatus::DRAFT]);
    $submitted = staffCase();

    foreach ([$draft, $submitted] as $case) {
        if ($case->is($draft)) {
            (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'Draft item'])->save();
        }
        (new CaseQuotation)->forceFill(['case_id' => $case->id, 'supplier_name' => 'S', 'unit_price' => '1', 'quantity_offered' => '1'])->save();
        makeDocument($case, 'signed_request');
    }

    foreach (['cmo', 'ps_office'] as $role) {
        $this->actingAs(caseUser($role));
        $ids = [$draft->id, $submitted->id];

        expect(CaseRequisition::whereIn('case_id', $ids)->pluck('case_id')->all())->toBe([$submitted->id])
            ->and(CaseQuotation::whereIn('case_id', $ids)->pluck('case_id')->unique()->values()->all())->toBe([$submitted->id])
            ->and(Document::whereIn('case_id', $ids)->pluck('case_id')->unique()->values()->all())->toBe([$submitted->id])
            ->and(ProcurementCase::whereIn('id', $ids)->pluck('id')->all())->toBe([$submitted->id]);
    }
});

it('still lets the owning vendor see its DRAFT', function () {
    $org = vendorOrg();
    $draft = makeCase(['org_id' => $org->id, 'status' => CaseStatus::DRAFT]);

    $this->actingAs(vendorUser($org));

    expect(ProcurementCase::whereKey($draft->id)->exists())->toBeTrue();
});

// 3. List -------------------------------------------------------------------------

it('lists the expected columns, without drafts', function () {
    $org = vendorOrg();
    $case = staffCase(['org_id' => $org->id, 'submitted_at' => now()->subDays(3)]);
    makeCase(['org_id' => $org->id, 'status' => CaseStatus::DRAFT]);

    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.index', ['org' => $org->id])));
    $row = $props['cases']['data'][0];

    expect($props['cases']['data'])->toHaveCount(1)
        ->and(array_keys($row))->toBe([
            'id', 'reference_no', 'organisation', 'procurement_type', 'item_description', 'status',
            'status_label', 'status_tone', 'holder_label', 'submitted_at', 'days_since_submitted',
        ])
        ->and($row['status_tone'])->toBe('info') // SUBMITTED is info (CaseStatus::tone; palette in app.css)
        ->and($row['reference_no'])->toBe($case->reference_no)
        ->and($row['organisation'])->toBe($org->name)
        ->and($row['status_label'])->toBe('Submitted')
        ->and($row['holder_label'])->toBe('CMO Registry')
        ->and($row['days_since_submitted'])->toBe(3);
});

it('truncates the item description on the server', function () {
    $org = vendorOrg();
    $case = staffCase(['org_id' => $org->id]);
    CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->update(['item_description' => str_repeat('x', 200)]);

    $row = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.index', ['org' => $org->id])))['cases']['data'][0];

    expect(mb_strlen($row['item_description']))->toBeLessThanOrEqual(83);
});

it('filters by status, procurement type, organisation and reference', function () {
    $org = vendorOrg();
    $cmo = caseUser(Role::Cmo);
    $a = staffCase(['org_id' => $org->id, 'reference_no' => 'NR-9A-ALPHA1']);
    $b = staffCase(['org_id' => $org->id, 'status' => CaseStatus::IN_REVIEW, 'reference_no' => 'NR-9A-BRAVO2']);

    expect(listedIds($cmo, $org, ['status' => 'IN_REVIEW']))->toBe([$b->id])
        ->and(listedIds($cmo, $org, ['ref' => 'ALPHA']))->toBe([$a->id])
        ->and(listedIds($cmo, $org, ['type' => $a->procurement_type_id]))->toHaveCount(2)
        ->and(listedIds($cmo, vendorOrg()))->toBe([]);
});

it('sorts by the whitelisted columns', function () {
    $org = vendorOrg();
    $cmo = caseUser(Role::Cmo);
    $first = staffCase(['org_id' => $org->id, 'reference_no' => 'NR-9A-AAA', 'submitted_at' => now()->subDays(2)]);
    $second = staffCase(['org_id' => $org->id, 'reference_no' => 'NR-9A-BBB', 'submitted_at' => now()->subDay()]);

    expect(listedIds($cmo, $org))->toBe([$second->id, $first->id]) // default: submitted_at desc
        ->and(listedIds($cmo, $org, ['sort' => 'reference_no', 'dir' => 'asc']))->toBe([$first->id, $second->id])
        // days = days since submission: most days first means oldest submission first
        ->and(listedIds($cmo, $org, ['sort' => 'days', 'dir' => 'desc']))->toBe([$first->id, $second->id])
        ->and(listedIds($cmo, $org, ['sort' => 'days', 'dir' => 'asc']))->toBe([$second->id, $first->id]);
});

it('sends UI permission hints for the list page', function () {
    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.index')));

    expect($props['abilities'])->toBe(['create' => false, 'see_organisation' => true])
        ->and($props['can']['cases'])->toBeTrue(); // the shared nav flags survive
});

it('rejects invalid filter and sort values with validation errors', function (array $query, string $field) {
    $this->actingAs(caseUser(Role::Cmo))
        ->get(route('cases.index', $query))
        ->assertRedirect(route('cases.index'))
        ->assertSessionHasErrors($field);
})->with([
    'draft status' => [['status' => 'DRAFT'], 'status'],
    'unknown status' => [['status' => 'NOPE'], 'status'],
    'unknown type' => [['type' => 999999999], 'type'],
    'non-integer type' => [['type' => 'abc'], 'type'],
    'unknown org' => [['org' => '01zzzzzzzzzzzzzzzzzzzzzzzz'], 'org'],
    'short reference' => [['ref' => 'NR'], 'ref'],
    'wildcard reference' => [['ref' => 'NR%_x'], 'ref'],
    'unknown sort' => [['sort' => 'password'], 'sort'],
    'unknown direction' => [['dir' => 'sideways'], 'dir'],
    'bad page' => [['page' => 0], 'page'],
]);

it('rejects the MOH organisation as an organisation filter', function () {
    $this->actingAs(caseUser(Role::Cmo))
        ->get(route('cases.index', ['org' => mohOrg()->id]))
        ->assertSessionHasErrors('org');
});

it('paginates 25 to a page', function () {
    $org = vendorOrg();
    foreach (range(1, 27) as $i) {
        staffCase(['org_id' => $org->id]);
    }
    $cmo = caseUser(Role::Cmo);

    $first = pageProps($this->actingAs($cmo)->get(route('cases.index', ['org' => $org->id])))['cases'];
    $second = pageProps($this->actingAs($cmo)->get(route('cases.index', ['org' => $org->id, 'page' => 2])))['cases'];

    expect($first['data'])->toHaveCount(25)
        ->and($first['total'])->toBe(27)
        ->and($first['last_page'])->toBe(2)
        ->and($first['next_page_url'])->toContain('org='.$org->id)
        ->and($second['data'])->toHaveCount(2);
});

it('runs the same number of queries for 25 rows as for 2 (no N+1)', function () {
    $cmo = caseUser(Role::Cmo);
    $small = vendorOrg();
    $large = vendorOrg();
    foreach (range(1, 2) as $i) {
        staffCase(['org_id' => $small->id]);
    }
    foreach (range(1, 25) as $i) {
        staffCase(['org_id' => $large->id]);
    }

    $count = function (Organisation $org) use ($cmo) {
        $this->actingAs($cmo)->get(route('cases.index', ['org' => $org->id]))->assertOk(); // warm caches
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($cmo)->get(route('cases.index', ['org' => $org->id]))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($count($large))->toBe($count($small));
});

// 4. View -------------------------------------------------------------------------

it('returns 404 for a case outside the scope', function () {
    $case = staffCase();

    $this->actingAs(caseUser(Role::Technocrat))->get(route('cases.show', $case))->assertNotFound();
});

it('shows the header, requisition, holder and assignments', function () {
    $case = staffCase(['current_holder_role' => 'cmo']);
    $officer = caseUser(Role::Technocrat);
    assign($case, Role::CmoRegistry, completed: true);
    assign($case, Role::Technocrat, $officer);

    $response = $this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk();
    $props = ownProps($response);

    // Slice 9b added intake and record_hardcopy (true for the cmo on a SUBMITTED case);
    // slice 10a added forward_for_review (false: only a CMO_INTAKE case can be sent).
    expect(pageProps($response)['can'])->toBe([
        'requests' => false, 'cases' => true, 'download_documents' => true, 'intake' => true, 'record_hardcopy' => true,
        'forward_for_review' => false,
        // Slice 10b: false on a SUBMITTED case.
        'record_review' => false, 'mark_ready_for_file' => false,
        // Slice 10c: false on a SUBMITTED case.
        'prepare_file' => false, 'send_to_legal' => false, 'record_legal_decision' => false,
        // Slice 10d: false on a SUBMITTED case.
        'record_ps_decision' => false,
        // Slice 10e: false on a SUBMITTED case.
        'route_onward' => false,
        // Slice 10f: false on a SUBMITTED case.
        'issue_letter' => false, 'close_case' => false,
    ])
        ->and($props['case']['reference_no'])->toBe($case->reference_no)
        ->and($props['case']['holder_label'])->toBe('CMO')
        ->and($props['case']['requisition']['item_description'])->toBe('Item for '.$case->reference_no)
        ->and($props['assignments'])->toHaveCount(2)
        ->and($props['assignments'][0])->toMatchArray(['role_label' => 'CMO Registry', 'user' => null, 'state' => 'Completed'])
        ->and($props['assignments'][1])->toMatchArray(['role_label' => 'Technocrat', 'user' => $officer->name, 'state' => 'Active']);
});

it('shows the timeline oldest first with labels, actor and reason', function () {
    $case = staffCase(['status' => CaseStatus::WITHDRAWN]);
    $vendor = vendorUser($case->organisation()->first());
    $cmo = caseUser(Role::Cmo);

    DB::table('case_transitions')->insert([
        ['case_id' => $case->id, 'from_status' => 'SUBMITTED', 'to_status' => 'WITHDRAWN', 'actor_id' => $cmo->id, 'acting_for_id' => null, 'reason' => 'Vendor asked', 'created_at' => now()],
        ['case_id' => $case->id, 'from_status' => 'DRAFT', 'to_status' => 'SUBMITTED', 'actor_id' => $vendor->id, 'acting_for_id' => null, 'reason' => null, 'created_at' => now()->subDay()],
    ]);

    $timeline = ownProps($this->actingAs($cmo)->get(route('cases.show', $case)))['timeline'];

    expect($timeline)->toHaveCount(2)
        ->and($timeline[0])->toMatchArray(['from_label' => 'Draft', 'to_label' => 'Submitted', 'actor' => $vendor->name, 'reason' => null])
        ->and($timeline[1])->toMatchArray(['from_label' => 'Submitted', 'to_label' => 'Withdrawn', 'actor' => $cmo->name, 'reason' => 'Vendor asked']);
});

it('leaves the quotation technical fields out for every role', function (string $role) {
    $case = staffCase();
    $quotation = (new CaseQuotation)->forceFill(['case_id' => $case->id, 'supplier_name' => 'S', 'unit_price' => '1', 'quantity_offered' => '1']);
    $quotation->save();
    DB::table('case_quotations')->where('id', $quotation->id)->update(['technical_status' => 'SAMPLE_ACCEPTED']);
    $user = caseUser($role);
    assign($case, $role, $user);

    $props = ownProps($this->actingAs($user)->get(route('cases.show', $case))->assertOk());

    expect($props['case']['quotations'])->toHaveCount(1);
    foreach (scanProps($props)['keys'] as $key) {
        expect($key)->not->toStartWith('technical_');
    }
})->with(STAFF_ROLES_WITH_ACCESS);

it('lists only current CLEAN documents, internal and external', function () {
    $case = staffCase();
    $cmo = caseUser(Role::Cmo);
    $clean = makeDocument($case, 'signed_request');
    $internal = makeDocument($case, 'stock_evidence', ['visibility' => 'INTERNAL']);
    makeDocument($case, 'original_rfq', ['scan_status' => ScanStatus::PENDING]);
    makeDocument($case, 'evaluation_report', ['scan_status' => ScanStatus::INFECTED]);
    makeDocument($case, 'technical_evidence', ['scan_status' => ScanStatus::ERROR]);
    makeDocument($case, 'other_supporting', ['scan_status' => ScanStatus::PENDING, 'uploaded_by' => $cmo->id]); // even their own
    makeDocument($case, 'supplier_quotations', ['withdrawn_at' => now()]);
    $old = makeDocument($case, 'evaluation_report');
    makeDocument($case, 'evaluation_report', ['version' => 2, 'supersedes_id' => $old->id]);

    $documents = ownProps($this->actingAs($cmo)->get(route('cases.show', $case)))['documents'];

    expect(collect($documents)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$clean->id, $internal->id, Document::withoutGlobalScopes()->where('supersedes_id', $old->id)->value('id')])->sort()->values()->all())
        ->and(collect($documents)->firstWhere('id', $internal->id)['visibility'])->toBe('Internal')
        ->and(array_keys($documents[0]))->toBe(['id', 'display_name', 'purpose_label', 'version', 'uploaded_at', 'uploaded_by', 'visibility', 'can_download']);
});

it('shows staff the same derived values and totals as the vendor', function () {
    $org = vendorOrg();
    $case = staffCase(['org_id' => $org->id]);
    foreach ([['1.5975', '2800', '2800', true, '12.5'], ['0.754', '2000', null, false, null]] as $i => [$price, $qty, $rec, $isRec, $vat]) {
        (new CaseQuotation)->forceFill([
            'case_id' => $case->id, 'sort_order' => $i, 'supplier_name' => "S{$i}", 'unit_price' => $price,
            'quantity_offered' => $qty, 'recommended_quantity' => $rec, 'is_recommended' => $isRec, 'vat_rate' => $vat,
        ])->save();
    }
    $case->tenderAwards()->create(['supplier_name' => 'A', 'quantity_awarded' => '2800', 'unit_cost' => '1.25']);
    $case->tenderAwards()->create(['supplier_name' => 'B', 'quantity_awarded' => '1200', 'unit_cost' => '1.10']);
    $case->purchaseOrders()->create(['supplier_name' => 'A', 'po_number' => 'PO-1', 'quantity_ordered' => '3000', 'quantity_delivered' => '1200']);

    $vendor = pageProps($this->actingAs(vendorUser($org))->get(route('requests.show', $case))->assertOk())['case'];
    $staff = ownProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk())['case'];

    foreach (['requisition', 'quotations', 'tender_awards', 'purchase_orders', 'stock_expiries', 'totals'] as $key) {
        expect($staff[$key])->toEqual($vendor[$key]);
    }
    expect($staff['totals']['quotations']['recommended_total'])->toBe('4473.00');
});

// 5. Download ---------------------------------------------------------------------

it('redirects an allowed user to a signed link and audits the download', function () {
    $case = staffCase();
    $document = makeDocument($case, 'signed_request');
    $cmo = caseUser(Role::Cmo);

    $response = $this->actingAs($cmo)->get(route('cases.documents.download', [$case, $document]));

    $url = $response->assertRedirect()->headers->get('Location');
    expect($url)->toContain("/documents/{$document->id}/file")->toContain('signature=')
        ->and(DB::table('audit_logs')->where('action', 'document.downloaded')->where('subject_id', $document->id)->count())->toBe(1);

    $this->actingAs($cmo)->get($url)->assertOk();
    $this->actingAs(caseUser(Role::Cmo))->get($url)->assertForbidden(); // the link is bound to one user
});

it('lets an assigned officer download', function () {
    $case = staffCase();
    $document = makeDocument($case, 'signed_request');
    $officer = caseUser(Role::LegalOfficer);
    assign($case, Role::LegalOfficer, $officer, completed: true);

    $this->actingAs($officer)->get(route('cases.documents.download', [$case, $document]))->assertRedirect();
});

it('returns 404 for an out-of-scope case, a DRAFT or a document of another case', function () {
    $case = staffCase();
    $document = makeDocument($case, 'signed_request');
    $other = staffCase();
    $draft = makeCase(['status' => CaseStatus::DRAFT]);
    $draftDocument = makeDocument($draft, 'signed_request');

    $this->actingAs(caseUser(Role::Technocrat))->get(route('cases.documents.download', [$case, $document]))->assertNotFound();
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.documents.download', [$other, $document]))->assertNotFound();
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.documents.download', [$draft, $draftDocument]))->assertNotFound();

    expect(DB::table('audit_logs')->where('action', 'document.downloaded')->whereIn('subject_id', [$document->id, $draftDocument->id])->count())->toBe(0);
});

it('refuses a document that is not CLEAN', function (ScanStatus $status) {
    $case = staffCase();
    $cmo = caseUser(Role::Cmo);
    $document = makeDocument($case, 'signed_request', ['scan_status' => $status, 'uploaded_by' => $cmo->id]);

    $this->actingAs($cmo)->get(route('cases.documents.download', [$case, $document]))->assertNotFound();
})->with([ScanStatus::PENDING, ScanStatus::INFECTED, ScanStatus::ERROR]);

it('returns 403 to staff without document.download', function () {
    $case = staffCase();
    $document = makeDocument($case, 'signed_request');
    $viewer = caseUser();
    $viewer->givePermissionTo(Permission::CaseViewAll->value);

    $this->actingAs($viewer)->get(route('cases.show', $case))->assertOk();
    expect(pageProps($this->actingAs($viewer)->get(route('cases.show', $case)))['can']['download_documents'])->toBeFalse();
    $this->actingAs($viewer)->get(route('cases.documents.download', [$case, $document]))->assertForbidden();
});

// 6. Props and source guards ------------------------------------------------------

it('gives every one of the 14 statuses a distinct staff label', function () {
    $labels = array_map(fn (CaseStatus $s) => $s->label(), CaseStatus::cases());

    expect($labels)->toHaveCount(14)
        ->and(array_unique($labels))->toHaveCount(14);
});

it('never puts raw model or storage internals in the props', function () {
    $org = vendorOrg();
    $case = staffCase(['org_id' => $org->id]);
    makeDocument($case, 'signed_request');
    $cmo = caseUser(Role::Cmo);

    $forbidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'email',
        'storage_key', 'sha256', 'mime', 'scan_status', 'scan_detail', 'scan_attempts', 'scanned_at', 'released_at',
        'withdrawn_at', 'withdrawn_by', 'supersedes_id', 'org_id', 'created_by', 'uploaded_by_id', 'actor_id',
        'technical_track', 'funding_track', 'hardcopy_received_by',
    ];

    foreach ([
        ownProps($this->actingAs($cmo)->get(route('cases.index', ['org' => $org->id]))),
        ownProps($this->actingAs($cmo)->get(route('cases.show', $case))),
    ] as $props) {
        $keys = scanProps($props)['keys'];
        foreach ($forbidden as $key) {
            expect($keys)->not->toContain($key);
        }
    }
});

it('keeps the staff case pages free of ad hoc network and storage calls', function () {
    $files = [
        ...glob(resource_path('js/pages/cases/*.tsx')),
        resource_path('js/components/requests/requisition-view.tsx'),
        resource_path('js/types/cases.ts'),
    ];

    expect($files)->toHaveCount(4);

    foreach ($files as $file) {
        $source = file_get_contents($file);

        foreach (['fetch(', 'axios', 'XMLHttpRequest', 'localStorage', 'sessionStorage', 'IndexedDB', 'indexedDB', 'dangerouslySetInnerHTML'] as $banned) {
            expect(str_contains($source, $banned))->toBeFalse(basename($file)." contains {$banned}");
        }
    }
});

// The pill tones (CaseStatus::tone / vendorTone; colours: the status palette in
// resources/css/app.css). The vendor tone never reveals an internal stage.
it('gives every status its tone, for staff and for the vendor', function (CaseStatus $status, string $staff, string $vendor) {
    expect($status->tone())->toBe($staff)
        ->and($status->vendorTone())->toBe($vendor);
})->with([
    [CaseStatus::DRAFT, 'neutral', 'neutral'],
    [CaseStatus::SUBMITTED, 'info', 'info'],
    [CaseStatus::CMO_INTAKE, 'indigo', 'violet'],
    [CaseStatus::IN_REVIEW, 'violet', 'violet'],
    [CaseStatus::READY_FOR_FILE, 'teal', 'violet'],
    [CaseStatus::FILE_PREPARED, 'cyan', 'violet'],
    [CaseStatus::WITH_LEGAL, 'purple', 'violet'],
    [CaseStatus::WITH_PS, 'fuchsia', 'violet'],
    [CaseStatus::RETURNED_TO_CMO, 'warning', 'violet'],
    [CaseStatus::NON_OBJECTION_GRANTED, 'success', 'violet'],
    [CaseStatus::LETTER_ISSUED, 'lime', 'success'],
    [CaseStatus::CLOSED, 'slate', 'slate'],
    [CaseStatus::WITHDRAWN, 'neutral', 'neutral'],
    [CaseStatus::REJECTED_FINAL, 'danger', 'danger'],
]);
