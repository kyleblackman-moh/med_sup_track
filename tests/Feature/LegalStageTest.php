<?php

use App\Enums\CaseStatus;
use App\Enums\ReviewDesk;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Models\CaseAssignment;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\PrepareFile;
use App\Workflow\Actions\RecordLegalDecision;
use App\Workflow\Actions\RouteReturnedCase;
use App\Workflow\Actions\SendToLegal;
use App\Workflow\CaseTransitionService;
use App\Workflow\Exceptions\GateNotPassed;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Slice 10c: prepare file (T5), send to Legal (T6), Legal decision (T7/T8),
// the returned banner and the hardcopy single source. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const FILE_NO_10C = 'HE:8/2/50/10C';

/** A case at $status with the gate passed and (from FILE_PREPARED on) a file number. */
function case10c(CaseStatus $status, array $attributes = []): ProcurementCase
{
    $recorder = caseUser(Role::CmoRegistry);
    $past = in_array($status, [CaseStatus::FILE_PREPARED, CaseStatus::WITH_LEGAL, CaseStatus::WITH_PS, CaseStatus::RETURNED_TO_CMO, CaseStatus::CLOSED], true);

    return makeCase([
        'status' => $status,
        'reference_no' => 'NR-10C-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(5),
        'technical_track' => TrackStatus::FAVOURABLE,
        'funding_track' => TrackStatus::CONFIRMED,
        'hardcopy_received_on' => CarbonImmutable::now('America/Port_of_Spain')->subDays(2)->toDateString(),
        'hardcopy_received_at' => CarbonImmutable::now('UTC')->subDays(2),
        'hardcopy_received_by' => $recorder->id,
        'file_no' => $past ? FILE_NO_10C : null,
        ...$attributes,
    ]);
}

/** An active Legal-desk assignment as SendToLegal creates it. */
function legalRow10c(ProcurementCase $case, User $user): CaseAssignment
{
    $row = new CaseAssignment;
    $row->forceFill([
        'case_id' => $case->id,
        'role' => SendToLegal::assignmentRole(),
        'purpose' => SendToLegal::PURPOSE,
        'user_id' => $user->id,
        'assigned_at' => now(),
    ])->save();

    return $row;
}

function legalOfficer10c(array $attributes = []): User
{
    return caseUser(Role::LegalOfficer, ['name' => 'Legal '.Str::upper(Str::random(6)), ...$attributes]);
}

function row10c(ProcurementCase $case): object
{
    return DB::table('cases')->where('id', $case->id)->first();
}

function transitions10c(ProcurementCase $case): int
{
    return DB::table('case_transitions')->where('case_id', $case->id)->count();
}

function audits10c(ProcurementCase $case, string $action): Collection
{
    return DB::table('audit_logs')->where('case_id', $case->id)->where('action', $action)->get();
}

/** Throws when an audit row with $action is written (fail-closed tests). */
function failAudit10c(string $action): void
{
    DB::listen(function ($query) use ($action) {
        if (str_contains($query->sql, 'audit_logs') && in_array($action, $query->bindings, true)) {
            throw new RuntimeException('audit store unavailable');
        }
    });
}

// 1. Policy matrix ---------------------------------------------------------------

const ROLES_10C = ['cmo', 'cmo_registry', 'technocrat', 'cab_officer', 'legal_assigned', 'legal_unassigned', 'ps_office', 'system_admin', 'auditor', 'vendor'];

const STATUSES_10C = ['DRAFT', 'IN_REVIEW', 'READY_FOR_FILE', 'FILE_PREPARED', 'WITH_LEGAL', 'WITH_PS', 'RETURNED_TO_CMO', 'CLOSED'];

function matrixActor10c(string $role, ProcurementCase $case): User
{
    return match ($role) {
        'legal_assigned' => tap(legalOfficer10c(), fn (User $u) => legalRow10c($case, $u)),
        'legal_unassigned' => legalOfficer10c(),
        'vendor' => vendorUser($case->organisation()->first()),
        'cmo_registry', 'technocrat', 'cab_officer' => tap(caseUser($role), fn (User $u) => assign($case, $role, $u)),
        default => caseUser($role),
    };
}

/** @return 'ok'|'error'|'forbidden'|'notfound' */
function expected10c(string $action, string $role, CaseStatus $status): string
{
    [$allowed, $at] = [
        'prepare' => ['cmo', CaseStatus::READY_FOR_FILE],
        'send' => ['cmo', CaseStatus::FILE_PREPARED],
        'decide' => ['legal_assigned', CaseStatus::WITH_LEGAL],
    ][$action];

    return match (true) {
        $role === 'vendor' => 'forbidden',                                                 // route middleware
        in_array($role, ['system_admin', 'auditor', 'legal_unassigned'], true) => 'notfound', // cannot see it
        $status === CaseStatus::DRAFT => 'notfound',                                        // staff never see drafts
        $role !== $allowed => 'forbidden',                                                  // permission (or assignment) missing
        $status->isTerminal() => 'forbidden',                                               // act() refuses terminal cases
        $status === $at => 'ok',
        default => 'error',
    };
}

it('allows each Legal-stage action exactly as the policy matrix says', function (string $action, string $role, string $statusValue) {
    $status = CaseStatus::from($statusValue);
    $case = case10c($status);
    $actor = matrixActor10c($role, $case);
    $officer = legalOfficer10c();

    [$route, $payload, $errorKey, $to] = match ($action) {
        'prepare' => ['cases.prepare', ['file_no' => 'HE:8/2/50/1'], 'prepare', 'FILE_PREPARED'],
        'send' => ['cases.legal.send', ['legal_officers' => [$officer->id]], 'send_to_legal', 'WITH_LEGAL'],
        'decide' => ['cases.legal.decide', ['decision' => 'confirmed'], 'legal_decision', 'WITH_PS'],
    };

    $response = $this->actingAs($actor)->post(route($route, $case), $payload);
    $expected = expected10c($action, $role, $status);

    match ($expected) {
        'ok' => $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success'),
        'error' => $response->assertRedirect()->assertSessionHasErrors($errorKey),
        'forbidden' => $response->assertForbidden(),
        'notfound' => $response->assertNotFound(),
    };

    expect(row10c($case)->status)->toBe($expected === 'ok' ? $to : $status->value)
        ->and(transitions10c($case))->toBe($expected === 'ok' ? 1 : 0);
})->with(['prepare', 'send', 'decide'])->with(ROLES_10C)->with(STATUSES_10C);

it('refuses a Legal decision from an officer whose assignment is completed', function () {
    $case = case10c(CaseStatus::WITH_LEGAL);
    $officer = legalOfficer10c();
    legalRow10c($case, $officer)->forceFill(['completed_at' => now()])->save();

    // Completed rows keep read-only visibility, so 403, not 404.
    $this->actingAs($officer)->post(route('cases.legal.decide', $case), ['decision' => 'confirmed'])->assertForbidden();
    expect(row10c($case)->status)->toBe('WITH_LEGAL');
});

it('refuses a Legal decision from a case.view_all holder with legal.confirm but no Legal assignment', function () {
    $case = case10c(CaseStatus::WITH_LEGAL);
    $user = caseUser(Role::Cmo);
    $user->assignRole(Role::LegalOfficer->value);

    $this->actingAs($user)->post(route('cases.legal.decide', $case), ['decision' => 'confirmed'])->assertForbidden();
    expect(row10c($case)->status)->toBe('WITH_LEGAL')->and(transitions10c($case))->toBe(0);
});

// 2. Prepare file (T5) -----------------------------------------------------------

it('prepares the file: trims and stores the number, one transition, audited with the number', function () {
    $case = case10c(CaseStatus::READY_FOR_FILE);
    $cmo = caseUser(Role::Cmo);

    $this->actingAs($cmo)->post(route('cases.prepare', $case), ['file_no' => '  HE:8/2/50/142  '])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'File prepared.');

    $row = row10c($case);
    $transition = DB::table('case_transitions')->where('case_id', $case->id)->first();
    $audit = audits10c($case, 'case.file_prepared');

    expect($row->file_no)->toBe('HE:8/2/50/142')
        ->and($row->status)->toBe('FILE_PREPARED')
        ->and($row->current_holder_role)->toBe('cmo')
        ->and($row->file_prepared_at)->not->toBeNull()
        ->and(transitions10c($case))->toBe(1)
        ->and($transition->from_status)->toBe('READY_FOR_FILE')
        ->and($transition->to_status)->toBe('FILE_PREPARED')
        ->and($audit)->toHaveCount(1)
        ->and(json_decode($audit[0]->metadata, true))->toBe(['file_no' => 'HE:8/2/50/142']);
});

it('requires a file number of 1 to 30 characters', function (array $payload) {
    $case = case10c(CaseStatus::READY_FOR_FILE);

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.prepare', $case), $payload)->assertSessionHasErrors('file_no');

    expect(row10c($case)->file_no)->toBeNull()->and(row10c($case)->status)->toBe('READY_FOR_FILE');
})->with([
    'missing' => [[]],
    'empty' => [['file_no' => '']],
    'spaces only' => [['file_no' => '     ']],
    '31 characters' => [['file_no' => str_repeat('9', 31)]],
]);

it('pre-fills the form from config and stores whatever the CMO types, prefix or not', function (string $typed) {
    $case = case10c(CaseStatus::READY_FOR_FILE);
    $cmo = caseUser(Role::Cmo);

    $props = pageProps($this->actingAs($cmo)->get(route('cases.show', $case))->assertOk());
    expect(config('cases.file_no_prefix'))->toBe('HE:8/2/50')
        ->and($props['file_no_default'])->toBe(config('cases.file_no_prefix'))
        ->and($props['can']['prepare_file'])->toBeTrue();

    $this->actingAs($cmo)->post(route('cases.prepare', $case), ['file_no' => $typed])->assertSessionHasNoErrors();
    expect(row10c($case)->file_no)->toBe($typed);
})->with(['HE:8/2/50', 'HE:8/2/50/77', 'Other series 12']);

it('allows the same file number on more than one case', function () {
    $cmo = caseUser(Role::Cmo);
    $a = case10c(CaseStatus::READY_FOR_FILE);
    $b = case10c(CaseStatus::READY_FOR_FILE);

    $this->actingAs($cmo)->post(route('cases.prepare', $a), ['file_no' => 'HE:8/2/50'])->assertSessionHasNoErrors();
    $this->actingAs($cmo)->post(route('cases.prepare', $b), ['file_no' => 'HE:8/2/50'])->assertSessionHasNoErrors();

    expect(row10c($a)->file_no)->toBe('HE:8/2/50')->and(row10c($b)->file_no)->toBe('HE:8/2/50');
});

it('answers a double click with AlreadyPrepared and no extra rows', function () {
    $case = case10c(CaseStatus::READY_FOR_FILE);
    $cmo = caseUser(Role::Cmo, ['name' => 'First Preparer']);

    $this->actingAs($cmo)->post(route('cases.prepare', $case), ['file_no' => 'HE:8/2/50/5'])->assertSessionHasNoErrors();
    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.prepare', $case), ['file_no' => 'HE:8/2/50/6'])
        ->assertSessionHasNoErrors()->assertSessionHas('info', fn (string $m) => str_contains($m, 'already prepared as HE:8/2/50/5 by First Preparer'));

    expect(transitions10c($case))->toBe(1)
        ->and(audits10c($case, 'case.file_prepared'))->toHaveCount(1)
        ->and(row10c($case)->file_no)->toBe('HE:8/2/50/5');

    $result = app(PrepareFile::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $cmo, 'X');
    expect($result->already)->toBeTrue();
});

it('leaves the status and file number unchanged when the prepare audit fails', function () {
    $case = case10c(CaseStatus::READY_FOR_FILE);
    failAudit10c('case.file_prepared');

    expect(fn () => app(PrepareFile::class)->handle($case, caseUser(Role::Cmo), 'HE:8/2/50/9'))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(row10c($case)->status)->toBe('READY_FOR_FILE')
        ->and(row10c($case)->file_no)->toBeNull()
        ->and(row10c($case)->file_prepared_at)->toBeNull()
        ->and(transitions10c($case))->toBe(0);
});

// 3. Send to Legal (T6) ----------------------------------------------------------

it('sends to Legal: assignments, holder, instruction as reason, counts-only audit', function () {
    $case = case10c(CaseStatus::FILE_PREPARED);
    $tech = caseUser(Role::Technocrat);
    $techRow = assign($case, Role::Technocrat, $tech);
    [$l1, $l2] = [legalOfficer10c(), legalOfficer10c()];

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.legal.send', $case), [
        'legal_officers' => [$l1->id, $l2->id],
        'instruction' => 'Secret instruction text',
    ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Case sent to Legal.');

    $rows = DB::table('case_assignments')->where('case_id', $case->id)->where('purpose', 'legal')->orderBy('user_id')->get();
    $transition = DB::table('case_transitions')->where('case_id', $case->id)->first();
    $audit = audits10c($case, 'case.sent_to_legal');

    expect(row10c($case)->status)->toBe('WITH_LEGAL')
        ->and(row10c($case)->current_holder_role)->toBe('legal_officer')
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('role')->unique()->all())->toBe(['legal_officer'])
        ->and($rows->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe(collect([$l1->id, $l2->id])->sort()->values()->all())
        ->and($rows->whereNotNull('completed_at')->count())->toBe(0)
        ->and($transition->reason)->toBe('Secret instruction text')
        ->and($audit)->toHaveCount(1)
        ->and(json_decode($audit[0]->metadata, true))->toBe(['legal_officers' => 2, 'instruction_given' => true])
        ->and(DB::table('case_assignments')->where('id', $techRow->id)->value('completed_at'))->toBeNull();
});

it('checks the chosen Legal officers', function (array $ids, bool $ok) {
    $case = case10c(CaseStatus::FILE_PREPARED);

    $response = $this->actingAs(caseUser(Role::Cmo))->post(route('cases.legal.send', $case), ['legal_officers' => $ids]);

    if ($ok) {
        $response->assertSessionHasNoErrors();
        expect(DB::table('case_assignments')->where('case_id', $case->id)->where('purpose', 'legal')->count())->toBe(1);
    } else {
        $response->assertSessionHasErrors();
        expect(row10c($case)->status)->toBe('FILE_PREPARED')
            ->and(DB::table('case_assignments')->where('case_id', $case->id)->count())->toBe(0);
    }
})->with([
    'none' => [fn () => [], false],
    'three' => [fn () => [legalOfficer10c()->id, legalOfficer10c()->id, legalOfficer10c()->id], false],
    'inactive' => [fn () => [legalOfficer10c(['status' => User::STATUS_DISABLED])->id], false],
    'wrong permission' => [fn () => [caseUser(Role::Technocrat)->id], false],
    'unknown id' => [fn () => [99999999], false],
    'not a number' => [fn () => ['abc'], false],
    'duplicate collapses to one' => [fn () => (fn (int $id) => [$id, $id, (string) $id])(legalOfficer10c()->id), true],
]);

it('lets the chosen Legal officer see the case and not an unchosen one', function () {
    $case = case10c(CaseStatus::FILE_PREPARED);
    [$chosen, $other] = [legalOfficer10c(), legalOfficer10c()];

    $this->actingAs($chosen)->get(route('cases.show', $case))->assertNotFound();
    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.legal.send', $case), ['legal_officers' => [$chosen->id]])->assertSessionHasNoErrors();

    $props = pageProps($this->actingAs($chosen)->get(route('cases.show', $case))->assertOk());
    expect($props['can']['record_legal_decision'])->toBeTrue()
        ->and($props['legal_form']['decisions'])->toBe([
            ['value' => 'confirmed', 'label' => 'Contract confirmed', 'needs_reason' => false, 'next' => 'The file goes to the PS Office.'],
            ['value' => 'return_to_cmo', 'label' => 'Return to CMO with a query', 'needs_reason' => true, 'next' => "The file returns to the CMO's Office."],
        ]);
    $this->actingAs($other)->get(route('cases.show', $case))->assertNotFound();
});

it('blocks WITH_LEGAL without a file number, even through the transition service', function () {
    $case = case10c(CaseStatus::FILE_PREPARED, ['file_no' => null]);
    $cmo = caseUser(Role::Cmo);

    expect(fn () => app(CaseTransitionService::class)->transition($case, CaseStatus::WITH_LEGAL, $cmo))
        ->toThrow(fn (GateNotPassed $e) => expect($e->missing)->toBe(['file_no']));

    $this->actingAs($cmo)->post(route('cases.legal.send', $case), ['legal_officers' => [legalOfficer10c()->id]])
        ->assertSessionHasErrors(['send_to_legal' => 'File number has not been entered.']);

    expect(row10c($case)->status)->toBe('FILE_PREPARED')
        ->and(transitions10c($case))->toBe(0)
        ->and(DB::table('case_assignments')->where('case_id', $case->id)->count())->toBe(0)
        ->and(TransitionRules::GUARD_MESSAGES['file_no'])->toBe('File number has not been entered');
});

it('blocks FILE_PREPARED without a file number through the transition service', function () {
    $case = case10c(CaseStatus::READY_FOR_FILE);

    expect(fn () => app(CaseTransitionService::class)->transition($case, CaseStatus::FILE_PREPARED, caseUser(Role::Cmo)))
        ->toThrow(GateNotPassed::class);
    expect(row10c($case)->status)->toBe('READY_FOR_FILE');
});

it('answers a repeat send with AlreadySent and no extra assignments', function () {
    $case = case10c(CaseStatus::FILE_PREPARED);
    $cmo = caseUser(Role::Cmo, ['name' => 'First Sender']);
    $officer = legalOfficer10c();

    $this->actingAs($cmo)->post(route('cases.legal.send', $case), ['legal_officers' => [$officer->id]])->assertSessionHasNoErrors();
    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.legal.send', $case), ['legal_officers' => [legalOfficer10c()->id]])
        ->assertSessionHasNoErrors()->assertSessionHas('info', fn (string $m) => str_contains($m, 'already sent to Legal by First Sender'));

    expect(transitions10c($case))->toBe(1)
        ->and(DB::table('case_assignments')->where('case_id', $case->id)->count())->toBe(1)
        ->and(audits10c($case, 'case.sent_to_legal'))->toHaveCount(1);
});

it('rolls back the send when its audit fails', function () {
    $case = case10c(CaseStatus::FILE_PREPARED);
    failAudit10c('case.sent_to_legal');

    expect(fn () => app(SendToLegal::class)->handle($case, caseUser(Role::Cmo), [legalOfficer10c()->id]))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(row10c($case)->status)->toBe('FILE_PREPARED')
        ->and(DB::table('case_assignments')->where('case_id', $case->id)->count())->toBe(0);
});

// 4. Legal decision (T7 / T8) ----------------------------------------------------

it('moves a confirmed case to WITH_PS where the PS office sees it', function () {
    $case = case10c(CaseStatus::WITH_LEGAL);
    $officer = legalOfficer10c();
    legalRow10c($case, $officer);

    $this->actingAs($officer)->post(route('cases.legal.decide', $case), ['decision' => 'confirmed', 'reason' => 'All clauses fine.'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Contract confirmed. The case is now with the PS office.');

    $transition = DB::table('case_transitions')->where('case_id', $case->id)->first();
    expect(row10c($case)->status)->toBe('WITH_PS')
        ->and(row10c($case)->current_holder_role)->toBe('ps_office')
        ->and($transition->reason)->toBe('All clauses fine.');

    $props = pageProps($this->actingAs(caseUser(Role::PsOffice))->get(route('cases.show', $case))->assertOk());
    expect($props['case']['status'])->toBe('WITH_PS');
});

it('requires a 10 to 500 character reason to return to the CMO', function (array $payload, ?string $errorKey) {
    $case = case10c(CaseStatus::WITH_LEGAL);
    $officer = legalOfficer10c();
    legalRow10c($case, $officer);

    $response = $this->actingAs($officer)->post(route('cases.legal.decide', $case), $payload);

    if ($errorKey) {
        $response->assertSessionHasErrors($errorKey);
        expect(row10c($case)->status)->toBe('WITH_LEGAL')->and(transitions10c($case))->toBe(0);
    } else {
        $response->assertSessionHasNoErrors()->assertSessionHas('success', 'Case returned to the CMO with a query.');
        expect(row10c($case)->status)->toBe('RETURNED_TO_CMO')
            ->and(row10c($case)->current_holder_role)->toBe('cmo')
            ->and(DB::table('case_transitions')->where('case_id', $case->id)->value('reason'))->toBe(trim($payload['reason']));
    }
})->with([
    'no reason' => [['decision' => 'return_to_cmo'], 'reason'],
    'short reason' => [['decision' => 'return_to_cmo', 'reason' => 'too short'], 'reason'],
    'blank reason' => [['decision' => 'return_to_cmo', 'reason' => '            '], 'reason'],
    '501 chars' => [['decision' => 'return_to_cmo', 'reason' => str_repeat('a', 501)], 'reason'],
    'good reason' => [['decision' => 'return_to_cmo', 'reason' => '  Clause 4 needs the vendor to clarify.  '], null],
    'confirmed note too long' => [['decision' => 'confirmed', 'reason' => str_repeat('a', 501)], 'reason'],
    'no decision' => [[], 'decision'],
    'not_confirmed is not a decision' => [['decision' => 'not_confirmed', 'reason' => 'Clause 4 needs work here.'], 'decision'],
]);

it('keeps the first Legal decision and names it to the second officer', function () {
    $case = case10c(CaseStatus::WITH_LEGAL);
    [$first, $second] = [legalOfficer10c(['name' => 'Officer One']), legalOfficer10c()];
    legalRow10c($case, $first);
    legalRow10c($case, $second);

    $this->actingAs($first)->post(route('cases.legal.decide', $case), ['decision' => 'confirmed'])->assertSessionHasNoErrors();
    $this->actingAs($second)->post(route('cases.legal.decide', $case), ['decision' => 'return_to_cmo', 'reason' => 'I would have returned it.'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('info', fn (string $m) => str_contains($m, 'already recorded (Contract confirmed) by Officer One'));

    expect(row10c($case)->status)->toBe('WITH_PS')
        ->and(transitions10c($case))->toBe(1)
        ->and(audits10c($case, 'case.legal_decision'))->toHaveCount(1);
});

it('audits the decision without the reason text', function () {
    $case = case10c(CaseStatus::WITH_LEGAL);
    $officer = legalOfficer10c();
    legalRow10c($case, $officer);

    $this->actingAs($officer)->post(route('cases.legal.decide', $case), ['decision' => 'return_to_cmo', 'reason' => 'Secret legal reason text.'])
        ->assertSessionHasNoErrors();

    $audit = audits10c($case, 'case.legal_decision');
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->metadata)->not->toContain('Secret legal reason')
        ->and(json_decode($audit[0]->metadata, true))->toBe(['decision' => 'return_to_cmo', 'reason_given' => true]);
});

it('changes nothing when the decision audit fails', function () {
    $case = case10c(CaseStatus::WITH_LEGAL);
    $officer = legalOfficer10c();
    legalRow10c($case, $officer);
    failAudit10c('case.legal_decision');

    expect(fn () => app(RecordLegalDecision::class)->handle($case, $officer, 'return_to_cmo', 'Clause 4 needs work.'))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(row10c($case)->status)->toBe('WITH_LEGAL')->and(transitions10c($case))->toBe(0);
});

// 5. Returned banner -------------------------------------------------------------

it('shows staff who returned the case, the reason, name and date', function () {
    $case = case10c(CaseStatus::WITH_LEGAL);
    $officer = legalOfficer10c(['name' => 'Banner Officer']);
    legalRow10c($case, $officer);
    $this->actingAs($officer)->post(route('cases.legal.decide', $case), ['decision' => 'return_to_cmo', 'reason' => 'Clause 4 needs the vendor.']);

    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk());

    expect($props['returned'])->toMatchArray(['source' => 'Legal', 'reason' => 'Clause 4 needs the vendor.', 'by' => 'Banner Officer'])
        ->and($props['returned']['at'])->not->toBeNull();
});

it('shows no banner at any other status', function (string $status) {
    $case = case10c(CaseStatus::from($status));

    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk());
    expect($props['returned'])->toBeNull();
})->with(['SUBMITTED', 'IN_REVIEW', 'READY_FOR_FILE', 'FILE_PREPARED', 'WITH_LEGAL', 'WITH_PS', 'CLOSED']);

it('names the source from the from_status of the latest return', function (string $from, ?string $source) {
    $case = case10c(CaseStatus::RETURNED_TO_CMO);
    DB::table('case_transitions')->insert([
        'case_id' => $case->id, 'from_status' => $from, 'to_status' => 'RETURNED_TO_CMO',
        'actor_id' => caseUser(Role::Cmo)->id, 'reason' => 'Some reason here', 'created_at' => now(),
    ]);

    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk());
    expect($props['returned']['source'])->toBe($source)
        ->and(CaseStatus::from($from)->returnedBySource())->toBe($source);
})->with([
    'Legal' => ['WITH_LEGAL', 'Legal'],
    'PS office' => ['WITH_PS', 'PS office'],
    'other' => ['IN_REVIEW', null],
]);

it('picks the latest return by id, not created_at, in the banner, the destinations and the T17 guard', function () {
    $case = case10c(CaseStatus::RETURNED_TO_CMO);
    $row = fn (string $from, CarbonImmutable $at) => DB::table('case_transitions')->insert([
        'case_id' => $case->id, 'from_status' => $from, 'to_status' => 'RETURNED_TO_CMO',
        'actor_id' => caseUser(Role::Cmo)->id, 'reason' => 'Some reason here', 'created_at' => $at,
    ]);
    // The newer row (higher id) carries the OLDER timestamp.
    $row('WITH_PS', CarbonImmutable::now('UTC'));
    $row('WITH_LEGAL', CarbonImmutable::now('UTC')->subDay());

    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk());
    $fresh = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);

    expect($props['returned']['source'])->toBe('Legal')
        ->and(RouteReturnedCase::latestReturn($fresh)->from_status)->toBe(CaseStatus::WITH_LEGAL)
        ->and(RouteReturnedCase::allowedDestinations($fresh))->not->toContain('ps')
        ->and(fn () => app(CaseTransitionService::class)->transition($fresh, CaseStatus::WITH_PS, caseUser(Role::Cmo), 'Back to the PS please.'))
        ->toThrow(GateNotPassed::class);
});

// 6. Hardcopy single source ------------------------------------------------------

it('treats the hardcopy as received only when the date is set, everywhere', function (bool $received) {
    $case = case10c(CaseStatus::IN_REVIEW, $received ? [] : [
        'hardcopy_received_on' => null, 'hardcopy_received_at' => null, 'hardcopy_received_by' => null,
    ]);
    reviewerRowFor10c($case);
    $case = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);

    $staff = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk());
    $vendor = pageProps($this->actingAs(vendorUser($case->organisation()->first()))->get(route('requests.show', $case))->assertOk());

    expect($case->hardcopyReceived())->toBe($received)
        ->and(in_array('hardcopy', TransitionRules::gateMissing($case), true))->toBe(! $received)
        ->and($staff['hardcopy'] !== null)->toBe($received)
        ->and($staff['gate']['rows'][2]['done'])->toBe($received)
        ->and($staff['gate']['rows'][2]['state'])->toBe($received ? 'Received' : 'Not received')
        ->and($vendor['case']['hardcopy_received_on'] !== null)->toBe($received);
})->with(['received' => true, 'not received' => false]);

/** A technocrat reviewer row so the gate tracker is built. */
function reviewerRowFor10c(ProcurementCase $case): void
{
    $row = new CaseAssignment;
    $row->forceFill([
        'case_id' => $case->id, 'role' => ReviewDesk::Technical->assignmentRole(), 'purpose' => 'technical',
        'user_id' => caseUser(Role::Technocrat)->id, 'assigned_at' => now(),
    ])->save();
}

it('rejects a partial hardcopy record at the database', function (array $columns) {
    $case = case10c(CaseStatus::IN_REVIEW, ['hardcopy_received_on' => null, 'hardcopy_received_at' => null, 'hardcopy_received_by' => null]);

    expect(fn () => DB::table('cases')->where('id', $case->id)->update($columns))
        ->toThrow(QueryException::class, 'ck_cases_hardcopy_all_or_none');
})->with([
    'recorder and time, no date' => [fn () => ['hardcopy_received_by' => caseUser(Role::Cmo)->id, 'hardcopy_received_at' => now()]],
    'date only' => [fn () => ['hardcopy_received_on' => now()->toDateString()]],
]);

// 7. Vendor leak -----------------------------------------------------------------

it('shows the vendor no file number, Legal names, decision, reason or banner', function () {
    $case = case10c(CaseStatus::READY_FOR_FILE);
    $cmo = caseUser(Role::Cmo);
    $officer = legalOfficer10c(['name' => 'Leaky Legal Name']);

    $this->actingAs($cmo)->post(route('cases.prepare', $case), ['file_no' => 'HE:8/2/50/LEAK'])->assertSessionHasNoErrors();
    $this->actingAs($cmo)->post(route('cases.legal.send', $case), ['legal_officers' => [$officer->id], 'instruction' => 'Instruction must not leak'])->assertSessionHasNoErrors();
    $this->actingAs($officer)->post(route('cases.legal.decide', $case), ['decision' => 'return_to_cmo', 'reason' => 'Reason must not leak to vendor'])->assertSessionHasNoErrors();

    $props = pageProps($this->actingAs(vendorUser($case->organisation()->first()))->get(route('requests.show', $case))->assertOk());
    $scan = scanProps(collect($props)->except(['auth'])->all());

    expectVendorSafe($props);
    expect($props['case']['status_label'] ?? null)->not->toBe('Returned to CMO');
    foreach (['file_no', 'file_no_default', 'legal_officers', 'legal_form', 'returned', 'decision', 'reason'] as $key) {
        expect($scan['keys'])->not->toContain($key);
    }
    foreach ($scan['strings'] as $string) {
        expect($string)->not->toContain('HE:8/2/50')
            ->and($string)->not->toContain('Leaky Legal Name')
            ->and($string)->not->toContain('must not leak')
            ->and($string)->not->toContain('Returned to CMO')
            ->and($string)->not->toContain('Contract confirmed');
    }
});
