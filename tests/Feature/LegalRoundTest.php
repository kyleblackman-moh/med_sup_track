<?php

use App\Enums\CaseStatus;
use App\Enums\ReviewDesk;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Http\Resources\Cases\StaffCaseDetail;
use App\Models\CaseAssignment;
use App\Models\CaseTrackEvent;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\MarkReadyForFile;
use App\Workflow\Actions\PrepareFile;
use App\Workflow\Actions\RecordLegalDecision;
use App\Workflow\Actions\RecordTechnicalReview;
use App\Workflow\Actions\RouteReturnedCase;
use App\Workflow\Actions\SendToLegal;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Slice cleanup-1: a second Send to Legal starts a new Legal round (T6 closes the
// active Legal rows first). DatabaseTransactions only. Time travel keeps one
// round's rows from sharing a timestamp with the next.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 13:00:00', 'UTC'));
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const FILE_NO_LR = 'HE:8/2/50/LR';

const INSTRUCTION_LR = 'Bring the signed clause seven copy.';

/** A case at $status with the gate passed and a file number. */
function caseLr(CaseStatus $status): ProcurementCase
{
    return makeCase([
        'status' => $status,
        'reference_no' => 'NR-LR-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(9),
        'technical_track' => TrackStatus::FAVOURABLE,
        'funding_track' => TrackStatus::CONFIRMED,
        'hardcopy_received_on' => CarbonImmutable::now('America/Port_of_Spain')->subDays(6)->toDateString(),
        'hardcopy_received_at' => CarbonImmutable::now('UTC')->subDays(6),
        'hardcopy_received_by' => caseUser(Role::CmoRegistry)->id,
        'file_no' => FILE_NO_LR,
    ]);
}

function rowLr(ProcurementCase $case, string $purpose, string $role, User $user): CaseAssignment
{
    $row = new CaseAssignment;
    $row->forceFill([
        'case_id' => $case->id, 'role' => $role, 'purpose' => $purpose,
        'user_id' => $user->id, 'assigned_at' => CarbonImmutable::now('UTC'),
    ])->save();

    return $row;
}

function legalRowLr(ProcurementCase $case, User $user): CaseAssignment
{
    return rowLr($case, SendToLegal::PURPOSE, SendToLegal::assignmentRole(), $user);
}

function legalUserLr(): User
{
    return caseUser(Role::LegalOfficer, ['name' => 'Legal '.Str::upper(Str::random(6))]);
}

/** Technical, funding and registry rows: none of them is ever touched by T6 or T12. @return list<int> */
function otherRowsLr(ProcurementCase $case): array
{
    return [
        rowLr($case, ReviewDesk::Technical->value, ReviewDesk::Technical->assignmentRole(), caseUser(Role::Technocrat))->id,
        rowLr($case, ReviewDesk::Funding->value, ReviewDesk::Funding->assignmentRole(), caseUser(Role::CabOfficer))->id,
        rowLr($case, 'intake', Role::CmoRegistry->value, caseUser(Role::CmoRegistry))->id,
    ];
}

function freshLr(ProcurementCase $case): ProcurementCase
{
    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

function legalRowsLr(ProcurementCase $case): Collection
{
    return DB::table('case_assignments')->where('case_id', $case->id)->where('purpose', 'legal')->orderBy('id')->get();
}

/** @return array<int, int> user_id => number of ACTIVE Legal rows */
function activeLegalPerUserLr(ProcurementCase $case): array
{
    return legalRowsLr($case)
        ->filter(fn (object $r) => $r->completed_at === null && $r->cancelled_at === null)
        ->countBy(fn (object $r) => (int) $r->user_id)
        ->sortKeys()->all();
}

function nonLegalRowsLr(ProcurementCase $case): array
{
    return DB::table('case_assignments')->where('case_id', $case->id)->where('purpose', '!=', 'legal')->orderBy('id')->get()->toArray();
}

function auditsLr(ProcurementCase $case, string $action): Collection
{
    return DB::table('audit_logs')->where('case_id', $case->id)->where('action', $action)->orderBy('id')->get();
}

// 1. Second Send to Legal ----------------------------------------------------------

it('starts a new Legal round on a second Send to Legal: old rows completed, one fresh active row per chosen officer', function () {
    $case = caseLr(CaseStatus::FILE_PREPARED);
    $kept = legalUserLr();    // chosen again
    $dropped = legalUserLr(); // not chosen this time
    $added = legalUserLr();   // new in this round
    $oldKept = legalRowLr($case, $kept);
    $oldDropped = legalRowLr($case, $dropped);
    otherRowsLr($case);
    $others = nonLegalRowsLr($case);

    $this->travel(1)->hours();
    app(SendToLegal::class)->handle(freshLr($case), caseUser(Role::Cmo), [(int) $kept->id, (int) $added->id], INSTRUCTION_LR);

    $rows = legalRowsLr($case)->keyBy('id');
    $active = legalRowsLr($case)->whereNull('completed_at')->whereNull('cancelled_at');

    expect(freshLr($case)->status)->toBe(CaseStatus::WITH_LEGAL)
        ->and($rows[$oldKept->id]->completed_at)->not->toBeNull()
        ->and($rows[$oldDropped->id]->completed_at)->not->toBeNull()
        ->and(activeLegalPerUserLr($case))->toBe(collect([(int) $kept->id => 1, (int) $added->id => 1])->sortKeys()->all())
        ->and($active->firstWhere('user_id', $kept->id)->id)->not->toBe($oldKept->id)
        ->and($active->every(fn (object $r) => $r->role === 'legal_officer'))->toBeTrue()
        ->and(nonLegalRowsLr($case))->toEqual($others);
});

it('leaves an unchosen earlier Legal officer read-only: sees the case, 403 on a decision; the chosen officer decides', function () {
    $case = caseLr(CaseStatus::FILE_PREPARED);
    $dropped = legalUserLr();
    $chosen = legalUserLr();
    legalRowLr($case, $dropped);

    $this->travel(1)->hours();
    app(SendToLegal::class)->handle(freshLr($case), caseUser(Role::Cmo), [(int) $chosen->id]);

    $props = pageProps($this->actingAs($dropped)->get(route('cases.show', $case))->assertOk());
    expect($props['can']['record_legal_decision'])->toBeFalse();

    $this->actingAs($dropped)->post(route('cases.legal.decide', $case), ['decision' => 'confirmed'])->assertForbidden();
    expect(freshLr($case)->status)->toBe(CaseStatus::WITH_LEGAL);

    $props = pageProps($this->actingAs($chosen)->get(route('cases.show', $case))->assertOk());
    expect($props['can']['record_legal_decision'])->toBeTrue();

    $this->actingAs($chosen)->post(route('cases.legal.decide', $case), ['decision' => 'confirmed'])->assertSessionHasNoErrors();
    expect(freshLr($case)->status)->toBe(CaseStatus::WITH_PS);
});

it('creates rows as before on a first Send to Legal and completes nothing', function () {
    $case = caseLr(CaseStatus::FILE_PREPARED);
    $officer = legalUserLr();
    otherRowsLr($case);
    $others = nonLegalRowsLr($case);

    app(SendToLegal::class)->handle(freshLr($case), caseUser(Role::Cmo), [(int) $officer->id]);

    expect(legalRowsLr($case))->toHaveCount(1)
        ->and(activeLegalPerUserLr($case))->toBe([(int) $officer->id => 1])
        ->and(auditsLr($case, 'case.assignment_completed'))->toHaveCount(0)
        ->and(nonLegalRowsLr($case))->toEqual($others);
});

// 2. T12 changes no assignments --------------------------------------------------

it('changes no assignments when the CMO routes a returned case back to Legal (T12)', function () {
    $case = caseLr(CaseStatus::RETURNED_TO_CMO);
    $officer = legalUserLr();
    legalRowLr($case, $officer);
    otherRowsLr($case);
    $before = DB::table('case_assignments')->where('case_id', $case->id)->orderBy('id')->get()->toArray();

    $this->travel(1)->hours();
    app(RouteReturnedCase::class)->handle(freshLr($case), caseUser(Role::Cmo), 'legal', 'Please look at clause four again.');

    expect(freshLr($case)->status)->toBe(CaseStatus::WITH_LEGAL)
        ->and(DB::table('case_assignments')->where('case_id', $case->id)->orderBy('id')->get()->toArray())->toEqual($before)
        ->and(auditsLr($case, 'case.assignment_completed'))->toHaveCount(0);
});

// 3. Audit ---------------------------------------------------------------------------

it('audits each closed Legal row with the code new_legal_round and no free text', function () {
    $case = caseLr(CaseStatus::FILE_PREPARED);
    $a = legalUserLr();
    $b = legalUserLr();
    $rowA = legalRowLr($case, $a);
    $rowB = legalRowLr($case, $b);

    $this->travel(1)->hours();
    app(SendToLegal::class)->handle(freshLr($case), caseUser(Role::Cmo), [(int) $a->id], INSTRUCTION_LR);

    $completed = auditsLr($case, 'case.assignment_completed');
    expect($completed)->toHaveCount(2)
        ->and($completed->pluck('subject_id')->all())->toBe([(string) $rowA->id, (string) $rowB->id])
        ->and(json_decode($completed[0]->metadata, true))->toBe(['role' => 'legal_officer', 'user_id' => (int) $a->id, 'purpose' => 'legal', 'reason' => 'new_legal_round'])
        ->and(json_decode($completed[1]->metadata, true))->toBe(['role' => 'legal_officer', 'user_id' => (int) $b->id, 'purpose' => 'legal', 'reason' => 'new_legal_round']);

    $all = DB::table('audit_logs')->where('case_id', $case->id)->pluck('metadata')->filter()->implode("\n");
    expect($all)->not->toContain('clause seven')
        ->and(json_decode(auditsLr($case, 'case.sent_to_legal')->sole()->metadata, true))->toBe(['legal_officers' => 1, 'instruction_given' => true]);
});

// 4. Full loop ---------------------------------------------------------------------

it('runs the full loop and ends with exactly one active Legal row per officer', function () {
    $case = caseLr(CaseStatus::WITH_LEGAL);
    $cmo = caseUser(Role::Cmo);
    $tech = caseUser(Role::Technocrat);
    $cab = caseUser(Role::CabOfficer);
    $a = legalUserLr();
    $b = legalUserLr();
    $x = caseUser(Role::Technocrat);
    rowLr($case, ReviewDesk::Technical->value, ReviewDesk::Technical->assignmentRole(), $tech);
    $funding = rowLr($case, ReviewDesk::Funding->value, ReviewDesk::Funding->assignmentRole(), $cab);
    legalRowLr($case, $a);
    $this->travel(5)->minutes();
    (new CaseTrackEvent)->forceFill(['case_id' => $case->id, 'track' => ReviewDesk::Technical, 'outcome' => 'favourable', 'actor_id' => $tech->id, 'created_at' => CarbonImmutable::now('UTC')])->save();
    (new CaseTrackEvent)->forceFill(['case_id' => $case->id, 'track' => ReviewDesk::Funding, 'outcome' => 'confirmed', 'actor_id' => $cab->id, 'created_at' => CarbonImmutable::now('UTC')])->save();
    $this->travel(1)->hours();

    app(RecordLegalDecision::class)->handle(freshLr($case), $a, 'return_to_cmo', 'Clause 4 needs a technical answer.');
    $this->travel(1)->hours();
    app(RouteReturnedCase::class)->handle(freshLr($case), $cmo, 'technocrats', 'Please look at the dosage table again.', [(int) $x->id]);
    $this->travel(1)->hours();
    app(RecordTechnicalReview::class)->handle(freshLr($case), $x, 'favourable');
    $this->travel(1)->minutes();
    app(MarkReadyForFile::class)->handle(freshLr($case), $cmo);
    app(PrepareFile::class)->handle(freshLr($case), $cmo, FILE_NO_LR);
    app(SendToLegal::class)->handle(freshLr($case), $cmo, [(int) $a->id, (int) $b->id]);
    $this->travel(1)->minutes();
    app(RecordLegalDecision::class)->handle(freshLr($case), $a, 'confirmed');

    expect(freshLr($case)->status)->toBe(CaseStatus::WITH_PS)
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->orderBy('id')->pluck('to_status')->all())
        ->toBe(['RETURNED_TO_CMO', 'IN_REVIEW', 'READY_FOR_FILE', 'FILE_PREPARED', 'WITH_LEGAL', 'WITH_PS'])
        ->and(activeLegalPerUserLr($case))->toBe(collect([(int) $a->id => 1, (int) $b->id => 1])->sortKeys()->all())
        ->and(legalRowsLr($case)->where('user_id', $a->id))->toHaveCount(2)
        ->and(DB::table('case_assignments')->where('id', $funding->id)->value('completed_at'))->toBeNull();
});

// 5. Duplicate dev-style rows ------------------------------------------------------

it('treats two active Legal rows for one user as one officer on the case page and the decision', function () {
    $case = caseLr(CaseStatus::WITH_LEGAL);
    $officer = legalUserLr();
    legalRowLr($case, $officer);
    legalRowLr($case, $officer);

    $props = pageProps($this->actingAs($officer)->get(route('cases.show', $case))->assertOk());
    expect($props['can']['record_legal_decision'])->toBeTrue();

    $this->actingAs($officer)->post(route('cases.legal.decide', $case), ['decision' => 'confirmed'])->assertSessionHasNoErrors();

    expect(freshLr($case)->status)->toBe(CaseStatus::WITH_PS)
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->count())->toBe(1)
        ->and(auditsLr($case, 'case.legal_decision'))->toHaveCount(1);
});

it('names a duplicated Legal officer once in the Send onward panel and counts one on T12', function () {
    $case = caseLr(CaseStatus::RETURNED_TO_CMO);
    $officer = legalUserLr();
    legalRowLr($case, $officer);
    legalRowLr($case, $officer);

    $panel = StaffCaseDetail::for(freshLr($case), caseUser(Role::Cmo))['route_panel'];
    $legal = collect($panel['destinations'])->firstWhere('value', 'legal');
    expect($legal['available'])->toBeTrue()
        ->and(substr_count($legal['consequence'], $officer->name))->toBe(1)
        ->and($legal['consequence'])->toContain('the Legal officer already assigned');

    app(RouteReturnedCase::class)->handle(freshLr($case), caseUser(Role::Cmo), 'legal', 'Please look at clause four again.');

    expect(json_decode(auditsLr($case, 'case.routed_onward')->sole()->metadata, true)['legal_officers'])->toBe(1);
});

it('closes both duplicate rows when a new Legal round starts', function () {
    $case = caseLr(CaseStatus::FILE_PREPARED);
    $officer = legalUserLr();
    legalRowLr($case, $officer);
    legalRowLr($case, $officer);

    $this->travel(1)->hours();
    app(SendToLegal::class)->handle(freshLr($case), caseUser(Role::Cmo), [(int) $officer->id]);

    expect(legalRowsLr($case))->toHaveCount(3)
        ->and(activeLegalPerUserLr($case))->toBe([(int) $officer->id => 1])
        ->and(auditsLr($case, 'case.assignment_completed'))->toHaveCount(2);
});
