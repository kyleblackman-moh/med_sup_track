<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Http\Controllers\HardcopyLookupController;
use App\Http\Resources\Requests\CoverSheet;
use App\Models\ProcurementCase;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// HARDCOPY-1: the cover sheet PDF, the reference lookup, and marking the
// hardcopy received (idempotent, with the gate re-checked). DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Mail::fake();

    $this->org = vendorOrg();
    $this->vendor = vendorUser($this->org);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function hcCase($t, S $status, array $attributes = []): ProcurementCase
{
    $case = makeCase([
        'org_id' => $t->org->id,
        'created_by' => $t->vendor->id,
        'status' => $status,
        'reference_no' => $status === S::DRAFT ? null : 'NR-2026-'.Str::upper(Str::random(6)),
        'submitted_at' => $status === S::DRAFT ? null : CarbonImmutable::now('UTC')->subDay(),
        ...$attributes,
    ]);

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

// 1. Cover sheet ---------------------------------------------------------------------

it('lets the vendor download its own cover sheet as a PDF with the reference, and audits it', function () {
    $case = hcCase($this, S::SUBMITTED);

    $response = $this->actingAs($this->vendor)->get(route('requests.cover-sheet', $case))->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('cover-sheet-'.$case->reference_no.'.pdf')
        ->and(str_starts_with($response->getContent(), '%PDF'))->toBeTrue()
        ->and($response->getContent())->toContain($case->reference_no)
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.cover_sheet_downloaded')->count())->toBe(1);
});

it('lets staff who can view the case download it too', function () {
    $case = hcCase($this, S::IN_REVIEW);

    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.cover-sheet', $case))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs(caseUser(Role::Technocrat))->get(route('cases.cover-sheet', $case))->assertNotFound();
});

it('refuses another organisation\'s cover sheet (404: out of scope) and a draft\'s', function () {
    $case = hcCase($this, S::SUBMITTED);
    $draft = hcCase($this, S::DRAFT);

    $this->actingAs(vendorUser(vendorOrg()))->get(route('requests.cover-sheet', $case))->assertNotFound();
    $this->actingAs($this->vendor)->get(route('requests.cover-sheet', $draft))->assertNotFound();
    expect(DB::table('audit_logs')->whereIn('case_id', [$case->id, $draft->id])->where('action', 'case.cover_sheet_downloaded')->count())->toBe(0);
});

it('builds the sheet from vendor-safe fields only', function () {
    $case = hcCase($this, S::IN_REVIEW, ['file_no' => 'HE:8/2/50/SECRET', 'current_holder_role' => 'cmo']);

    $sheet = CoverSheet::for($case);

    expect(array_keys($sheet))->toBe(['title', 'reference', 'organisation', 'request_type', 'submitted_at', 'documents', 'instruction'])
        ->and($sheet['reference'])->toBe($case->reference_no)
        ->and($sheet['organisation'])->toBe($this->org->name)
        ->and(json_encode($sheet))->not->toContain('SECRET');
});

// 2. Reference lookup ------------------------------------------------------------------

it('opens the case on an exact reference match, ignoring case and outer spaces', function () {
    $case = hcCase($this, S::SUBMITTED);
    $registry = caseUser(Role::CmoRegistry);
    assign($case, Role::CmoRegistry, $registry);

    $this->actingAs($registry)->get(route('cases.hardcopy-lookup', ['reference' => '  '.Str::lower($case->reference_no).' ']))
        ->assertRedirect(route('cases.show', $case->id));
});

it('finds nothing for a partial reference or a case out of scope', function () {
    $case = hcCase($this, S::SUBMITTED);
    $registry = caseUser(Role::CmoRegistry);
    assign($case, Role::CmoRegistry, $registry);
    $hidden = hcCase($this, S::SUBMITTED);

    $this->actingAs($registry)->from(route('dashboard'))->get(route('cases.hardcopy-lookup', ['reference' => substr($case->reference_no, 0, -2)]))
        ->assertRedirect(route('dashboard'))->assertSessionHasErrors(['reference' => HardcopyLookupController::NOT_FOUND]);
    $this->actingAs($registry)->get(route('cases.hardcopy-lookup', ['reference' => $hidden->reference_no]))
        ->assertSessionHasErrors(['reference' => HardcopyLookupController::NOT_FOUND]);
});

it('shows the lookup on the dashboard only for hardcopy.confirm holders', function () {
    expect(pageProps($this->actingAs(caseUser(Role::CmoRegistry))->get(route('dashboard')))['hardcopyLookup'])->toBeTrue()
        ->and(pageProps($this->actingAs(caseUser(Role::Technocrat))->get(route('dashboard')))['hardcopyLookup'])->toBeFalse();
});

// 3. Mark received ------------------------------------------------------------------------

it('refuses a user without hardcopy.confirm (403)', function () {
    $case = hcCase($this, S::SUBMITTED);
    $tech = caseUser(Role::Technocrat);
    assign($case, Role::Technocrat, $tech);

    $this->actingAs($tech)->post(route('cases.hardcopy.store', $case))->assertForbidden();
    expect(DB::table('cases')->where('id', $case->id)->value('hardcopy_received_on'))->toBeNull();
});

it('marks the hardcopy received (today by default), audits it, and a second POST changes nothing', function () {
    $case = hcCase($this, S::SUBMITTED);
    $registry = caseUser(Role::CmoRegistry, ['name' => 'Registry Officer']);
    assign($case, Role::CmoRegistry, $registry);

    $this->actingAs($registry)->post(route('cases.hardcopy.store', $case))->assertSessionHas('success');

    $row = DB::table('cases')->where('id', $case->id)->first();
    expect($row->hardcopy_received_on)->toStartWith(CarbonImmutable::now('America/Port_of_Spain')->toDateString())
        ->and((int) $row->hardcopy_received_by)->toBe($registry->id)
        ->and($row->hardcopy_received_at)->not->toBeNull()
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.hardcopy_received')->count())->toBe(1);

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.hardcopy.store', $case))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('info', fn (string $m) => str_starts_with($m, 'Already received on ') && str_ends_with($m, ' by Registry Officer.'));

    expect(DB::table('cases')->where('id', $case->id)->value('hardcopy_received_at'))->toBe($row->hardcopy_received_at)
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.hardcopy_received')->count())->toBe(1);
});

it('refuses marking outside SUBMITTED, CMO_INTAKE and IN_REVIEW with a clear error', function () {
    $case = hcCase($this, S::WITH_LEGAL);

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.hardcopy.store', $case))->assertSessionHasErrors('hardcopy');
});

// 4. Gate ----------------------------------------------------------------------------------

it('moves the case to READY_FOR_FILE when the hardcopy completes the gate', function () {
    $case = hcCase($this, S::IN_REVIEW, ['technical_track' => TrackStatus::FAVOURABLE, 'funding_track' => TrackStatus::CONFIRMED]);
    $registry = caseUser(Role::CmoRegistry);
    assign($case, Role::CmoRegistry, $registry);

    $this->actingAs($registry)->post(route('cases.hardcopy.store', $case))->assertSessionHasNoErrors();

    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('READY_FOR_FILE');
    $t = DB::table('case_transitions')->where('case_id', $case->id)->sole();
    expect($t->from_status)->toBe('IN_REVIEW')->and($t->to_status)->toBe('READY_FOR_FILE')->and((int) $t->actor_id)->toBe($registry->id);
    expect(json_decode(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.transitioned')->value('metadata'), true)['rule'])->toBe('T4A');
});

it('leaves the case IN_REVIEW when another track is still open', function (array $tracks) {
    $case = hcCase($this, S::IN_REVIEW, $tracks);

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.hardcopy.store', $case))->assertSessionHasNoErrors();

    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('IN_REVIEW')
        ->and(DB::table('cases')->where('id', $case->id)->value('hardcopy_received_on'))->not->toBeNull()
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->count())->toBe(0);
})->with([
    'technical pending' => [['technical_track' => TrackStatus::PENDING, 'funding_track' => TrackStatus::CONFIRMED]],
    'funding pending' => [['technical_track' => TrackStatus::FAVOURABLE, 'funding_track' => TrackStatus::PENDING]],
]);
