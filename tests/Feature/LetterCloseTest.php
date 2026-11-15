<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Http\Resources\Cases\StaffCaseDetail;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\CloseCase;
use App\Workflow\Actions\IssueLetter;
use App\Workflow\Actions\RecordPsDecision;
use App\Workflow\CaseTransitionService;
use App\Workflow\Exceptions\GateNotPassed;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Slice 10f: record the letter as issued (T13) and close the case (T14).
// DatabaseTransactions only. Fixed clock: 12 Nov 2026, 11:00 in Port of Spain.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-12 15:00:00', 'UTC'));
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const LETTER_DATE_10F = '2026-11-09';
const EMAILED_10F = '2026-11-10';
const HARDCOPY_10F = '2026-11-11';

/** The letter columns as IssueLetter writes them (all together, for the CHECK constraints). */
function letterColumns10f(): array
{
    return [
        'letter_date' => LETTER_DATE_10F,
        'letter_reference' => 'MOH/NOL/10F',
        'letter_emailed_on' => EMAILED_10F,
        'letter_hardcopy_sent_on' => null,
        'letter_recorded_by' => caseUser(Role::Cmo)->id,
        'letter_recorded_at' => CarbonImmutable::now('UTC')->subDay(),
    ];
}

/** A case at $status with a passed gate and a file number; LETTER_ISSUED and CLOSED carry a letter. */
function case10f(CaseStatus $status, bool $withLetter = true): ProcurementCase
{
    return makeCase([
        'status' => $status,
        'reference_no' => 'NR-10F-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(20),
        'technical_track' => TrackStatus::FAVOURABLE,
        'funding_track' => TrackStatus::CONFIRMED,
        'hardcopy_received_on' => '2026-10-25',
        'hardcopy_received_at' => CarbonImmutable::now('UTC')->subDays(18),
        'hardcopy_received_by' => caseUser(Role::CmoRegistry)->id,
        'file_no' => 'HE:8/2/50/10F',
        ...($withLetter && in_array($status, [CaseStatus::LETTER_ISSUED, CaseStatus::CLOSED], true) ? letterColumns10f() : []),
    ]);
}

function fresh10f(ProcurementCase $case): ProcurementCase
{
    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

function row10f(ProcurementCase $case): object
{
    return DB::table('cases')->where('id', $case->id)->first();
}

function transitions10f(ProcurementCase $case): Collection
{
    return DB::table('case_transitions')->where('case_id', $case->id)->orderBy('id')->get();
}

function letterRow10f(ProcurementCase $case): array
{
    return Arr::only((array) row10f($case), ['letter_date', 'letter_reference', 'letter_emailed_on', 'letter_hardcopy_sent_on', 'letter_recorded_by', 'letter_recorded_at']);
}

function issue10f(ProcurementCase $case, ?User $actor = null, array $input = []): mixed
{
    $input = ['letter_date' => LETTER_DATE_10F, 'letter_reference' => null, 'emailed_on' => EMAILED_10F, 'hardcopy_sent_on' => null, ...$input];

    return app(IssueLetter::class)->handle(
        fresh10f($case), $actor ?? caseUser(Role::Cmo),
        $input['letter_date'], $input['letter_reference'], $input['emailed_on'], $input['hardcopy_sent_on'],
    );
}

// 1. Policy matrix ---------------------------------------------------------------

const ROLES_10F = ['cmo', 'cmo_registry', 'technocrat', 'technocrat_unassigned', 'cab_officer', 'legal_officer', 'ps_office', 'system_admin', 'auditor', 'vendor'];

const STATUSES_10F = ['DRAFT', 'WITH_PS', 'NON_OBJECTION_GRANTED', 'LETTER_ISSUED', 'CLOSED'];

function matrixActor10f(string $role, ProcurementCase $case): User
{
    return match ($role) {
        'technocrat_unassigned' => caseUser(Role::Technocrat),
        'vendor' => vendorUser($case->organisation()->first()),
        'cmo_registry', 'technocrat', 'cab_officer', 'legal_officer' => tap(caseUser($role), fn (User $u) => assign($case, $role, $u)),
        default => caseUser($role),
    };
}

/** @return 'ok'|'error'|'forbidden'|'notfound' */
function expected10f(string $action, string $role, CaseStatus $status): string
{
    $at = $action === 'issue' ? CaseStatus::NON_OBJECTION_GRANTED : CaseStatus::LETTER_ISSUED;

    return match (true) {
        $role === 'vendor' => 'forbidden',                                                         // route middleware
        in_array($role, ['system_admin', 'auditor', 'technocrat_unassigned'], true) => 'notfound',   // cannot see it
        $status === CaseStatus::DRAFT => 'notfound',                                                // staff never see drafts
        $role !== 'cmo' => 'forbidden',                                                             // no letter.issue
        $status->isTerminal() => 'forbidden',                                                       // act() refuses terminal cases
        $status === $at => 'ok',
        default => 'error',
    };
}

it('allows issuing the letter and closing exactly as the policy matrix says', function (string $action, string $role, string $statusValue) {
    $status = CaseStatus::from($statusValue);
    $case = case10f($status);
    $actor = matrixActor10f($role, $case);

    $response = $action === 'issue'
        ? $this->actingAs($actor)->post(route('cases.letter.issue', $case), ['letter_date' => LETTER_DATE_10F, 'emailed_on' => EMAILED_10F])
        : $this->actingAs($actor)->post(route('cases.close', $case));
    $expected = expected10f($action, $role, $status);

    match ($expected) {
        'ok' => $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success'),
        'error' => $response->assertRedirect()->assertSessionHasErrors($action === 'issue' ? 'issue_letter' : 'close_case'),
        'forbidden' => $response->assertForbidden(),
        'notfound' => $response->assertNotFound(),
    };

    $to = $action === 'issue' ? 'LETTER_ISSUED' : 'CLOSED';
    expect(row10f($case)->status)->toBe($expected === 'ok' ? $to : $status->value)
        ->and(transitions10f($case))->toHaveCount($expected === 'ok' ? 1 : 0);
})->with(['issue', 'close'])->with(ROLES_10F)->with(STATUSES_10F);

it('gives only the CMO the issue and close flags, at the right status', function (string $role) {
    $granted = case10f(CaseStatus::NON_OBJECTION_GRANTED);
    $issued = case10f(CaseStatus::LETTER_ISSUED);
    $a = matrixActor10f($role, $granted);
    $b = matrixActor10f($role, $issued);

    $g = StaffCaseDetail::for(fresh10f($granted), $a);
    $i = StaffCaseDetail::for(fresh10f($issued), $b);

    expect($g['can']['issue_letter'])->toBe($role === 'cmo')
        ->and($g['letter_dates'] === null)->toBe($role !== 'cmo')
        ->and($g['can']['close_case'])->toBeFalse()
        ->and($i['can']['close_case'])->toBe($role === 'cmo')
        ->and($i['can']['issue_letter'])->toBeFalse();
})->with(['cmo', 'cmo_registry', 'technocrat', 'cab_officer', 'legal_officer', 'ps_office']);

// 2. Issue letter ----------------------------------------------------------------

it('validates the letter dates and reference', function (array $payload, string $errorKey) {
    $case = case10f(CaseStatus::NON_OBJECTION_GRANTED);

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.letter.issue', $case), $payload)->assertSessionHasErrors($errorKey);

    expect(row10f($case)->status)->toBe('NON_OBJECTION_GRANTED')
        ->and(array_filter(letterRow10f($case)))->toBe([])
        ->and(transitions10f($case))->toHaveCount(0);
})->with([
    'no letter date' => [['emailed_on' => EMAILED_10F], 'letter_date'],
    'bad letter date' => [['letter_date' => '12/11/2026', 'emailed_on' => EMAILED_10F], 'letter_date'],
    'impossible letter date' => [['letter_date' => '2026-02-30', 'emailed_on' => EMAILED_10F], 'letter_date'],
    'future letter date' => [['letter_date' => '2026-11-13', 'emailed_on' => EMAILED_10F], 'letter_date'],
    'no channel date' => [['letter_date' => LETTER_DATE_10F], 'emailed_on'],
    'blank channel dates' => [['letter_date' => LETTER_DATE_10F, 'emailed_on' => '', 'hardcopy_sent_on' => ''], 'emailed_on'],
    'future emailed date' => [['letter_date' => LETTER_DATE_10F, 'emailed_on' => '2026-11-13'], 'emailed_on'],
    'future hardcopy date' => [['letter_date' => LETTER_DATE_10F, 'hardcopy_sent_on' => '2026-11-13'], 'hardcopy_sent_on'],
    'bad hardcopy date' => [['letter_date' => LETTER_DATE_10F, 'hardcopy_sent_on' => 'tomorrow'], 'hardcopy_sent_on'],
    'reference too long' => [['letter_date' => LETTER_DATE_10F, 'emailed_on' => EMAILED_10F, 'letter_reference' => str_repeat('R', 41)], 'letter_reference'],
]);

it('accepts today in Port of Spain and either channel on its own', function (array $input) {
    $case = case10f(CaseStatus::NON_OBJECTION_GRANTED);

    $result = issue10f($case, null, $input);

    expect($result->already)->toBeFalse()->and(row10f($case)->status)->toBe('LETTER_ISSUED');
})->with([
    'emailed only, today' => [['letter_date' => '2026-11-12', 'emailed_on' => '2026-11-12', 'hardcopy_sent_on' => null]],
    'hardcopy only' => [['emailed_on' => null, 'hardcopy_sent_on' => HARDCOPY_10F]],
    'both' => [['hardcopy_sent_on' => HARDCOPY_10F]],
]);

it('stores the letter, transitions once and audits dates and a flag only', function () {
    $case = case10f(CaseStatus::NON_OBJECTION_GRANTED);
    $cmo = caseUser(Role::Cmo, ['name' => 'Letter Cmo']);

    issue10f($case, $cmo, ['letter_reference' => '   Zircon-ref 4471   ', 'hardcopy_sent_on' => HARDCOPY_10F]);

    $letter = letterRow10f($case);
    expect(row10f($case))->status->toBe('LETTER_ISSUED')->current_holder_role->toBe('cmo')
        ->and($letter['letter_date'])->toBe(LETTER_DATE_10F)
        ->and($letter['letter_reference'])->toBe('Zircon-ref 4471')
        ->and($letter['letter_emailed_on'])->toBe(EMAILED_10F)
        ->and($letter['letter_hardcopy_sent_on'])->toBe(HARDCOPY_10F)
        ->and((int) $letter['letter_recorded_by'])->toBe($cmo->id)
        ->and($letter['letter_recorded_at'])->not->toBeNull();

    $rows = transitions10f($case);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->from_status)->toBe('NON_OBJECTION_GRANTED')
        ->and($rows[0]->to_status)->toBe('LETTER_ISSUED')
        ->and($rows[0]->reason)->toBeNull();

    $audits = DB::table('audit_logs')->where('case_id', $case->id)->get();
    expect(json_decode($audits->where('action', 'case.letter_issued')->sole()->metadata, true))->toBe([
        'letter_date' => LETTER_DATE_10F, 'emailed_on' => EMAILED_10F, 'hardcopy_sent_on' => HARDCOPY_10F, 'has_reference' => true,
    ])
        ->and($audits->filter(fn ($a) => str_contains((string) $a->metadata, 'Zircon')))->toBeEmpty();
});

it('stores a blank reference as none', function () {
    $case = case10f(CaseStatus::NON_OBJECTION_GRANTED);

    issue10f($case, null, ['letter_reference' => '    ']);

    expect(letterRow10f($case)['letter_reference'])->toBeNull()
        ->and(json_decode(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.letter_issued')->value('metadata'), true)['has_reference'])->toBeFalse();
});

it('names who recorded the letter on a repeat and adds nothing', function () {
    $case = case10f(CaseStatus::NON_OBJECTION_GRANTED);
    issue10f($case, caseUser(Role::Cmo, ['name' => 'First Letter Cmo']));
    $before = [letterRow10f($case), transitions10f($case)->count(), DB::table('audit_logs')->where('case_id', $case->id)->count()];

    $second = issue10f($case, null, ['letter_date' => '2026-11-10', 'emailed_on' => '2026-11-11']);

    expect($second->already)->toBeTrue()
        ->and($second->by)->toBe('First Letter Cmo')
        ->and([letterRow10f($case), transitions10f($case)->count(), DB::table('audit_logs')->where('case_id', $case->id)->count()])->toEqual($before);

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.letter.issue', $case), ['letter_date' => LETTER_DATE_10F, 'emailed_on' => EMAILED_10F])
        ->assertSessionHas('info', fn (string $m) => str_contains($m, 'already recorded') && str_contains($m, 'First Letter Cmo'));
});

it('changes nothing when the letter audit fails', function () {
    $case = case10f(CaseStatus::NON_OBJECTION_GRANTED);
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs') && in_array('case.letter_issued', $query->bindings, true)) {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => issue10f($case))->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(row10f($case)->status)->toBe('NON_OBJECTION_GRANTED')
        ->and(array_filter(letterRow10f($case)))->toBe([])
        ->and(transitions10f($case))->toHaveCount(0);
});

it('refuses T13 through the service directly when no letter is recorded', function () {
    $case = case10f(CaseStatus::NON_OBJECTION_GRANTED);

    expect(fn () => app(CaseTransitionService::class)->transition(fresh10f($case), CaseStatus::LETTER_ISSUED, caseUser(Role::Cmo)))
        ->toThrow(GateNotPassed::class);

    expect(row10f($case)->status)->toBe('NON_OBJECTION_GRANTED');
});

it('replaces the T13 and T14 placeholders with real guards', function () {
    $find = fn (string $code) => collect(TransitionRules::all())->firstWhere('code', $code);

    expect($find('T13')->pendingGuards)->toBe([])->and($find('T13')->guard)->not->toBeNull()
        ->and($find('T14')->pendingGuards)->toBe([])->and($find('T14')->guard)->not->toBeNull()
        ->and($find('T14')->permission)->toBe('letter.issue')
        ->and(TransitionRules::GUARD_MESSAGES)->toHaveKeys(['letter_date', 'letter_channel']);
});

// 3. CHECK constraints (each raw statement is the last thing its test does) ------

it('refuses a letter date without who recorded it', function () {
    $case = case10f(CaseStatus::NON_OBJECTION_GRANTED);

    expect(fn () => DB::table('cases')->where('id', $case->id)->update(['letter_date' => LETTER_DATE_10F, 'letter_emailed_on' => EMAILED_10F]))
        ->toThrow(QueryException::class, 'ck_cases_letter_recorded_together');
});

it('refuses a recorder without a letter date', function () {
    $case = case10f(CaseStatus::NON_OBJECTION_GRANTED);

    expect(fn () => DB::table('cases')->where('id', $case->id)->update(['letter_recorded_by' => caseUser(Role::Cmo)->id, 'letter_recorded_at' => now()]))
        ->toThrow(QueryException::class, 'ck_cases_letter_recorded_together');
});

it('refuses a letter with no channel date', function () {
    $case = case10f(CaseStatus::NON_OBJECTION_GRANTED);

    expect(fn () => DB::table('cases')->where('id', $case->id)->update([
        'letter_date' => LETTER_DATE_10F, 'letter_recorded_by' => caseUser(Role::Cmo)->id, 'letter_recorded_at' => now(),
    ]))->toThrow(QueryException::class, 'ck_cases_letter_details');
});

it('refuses a reference or channel date without a letter date', function (string $column, string $value) {
    $case = case10f(CaseStatus::NON_OBJECTION_GRANTED);

    expect(fn () => DB::table('cases')->where('id', $case->id)->update([$column => $value]))
        ->toThrow(QueryException::class, 'ck_cases_letter_details');
})->with([
    'reference' => ['letter_reference', 'MOH/NOL/1'],
    'emailed on' => ['letter_emailed_on', EMAILED_10F],
    'hardcopy sent on' => ['letter_hardcopy_sent_on', HARDCOPY_10F],
]);

// 4. Close -----------------------------------------------------------------------

it('closes a case with one history row, closed_at and an audit row, assignments untouched', function () {
    $case = case10f(CaseStatus::LETTER_ISSUED);
    assign($case, Role::Technocrat, caseUser(Role::Technocrat));
    $assignments = DB::table('case_assignments')->where('case_id', $case->id)->orderBy('id')->get();

    $result = app(CloseCase::class)->handle(fresh10f($case), caseUser(Role::Cmo));

    expect($result->already)->toBeFalse()
        ->and(row10f($case))->status->toBe('CLOSED')->current_holder_role->toBeNull()
        ->and(row10f($case)->closed_at)->not->toBeNull()
        ->and(transitions10f($case))->toHaveCount(1)
        ->and(transitions10f($case)[0]->from_status)->toBe('LETTER_ISSUED')
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.closed')->count())->toBe(1)
        ->and(DB::table('case_assignments')->where('case_id', $case->id)->orderBy('id')->get())->toEqual($assignments);
});

it('names who closed it on a repeat and adds nothing', function () {
    $case = case10f(CaseStatus::LETTER_ISSUED);
    app(CloseCase::class)->handle(fresh10f($case), caseUser(Role::Cmo, ['name' => 'Closing Cmo']));
    $before = [(array) row10f($case), transitions10f($case)->count(), DB::table('audit_logs')->where('case_id', $case->id)->count()];

    $second = app(CloseCase::class)->handle(fresh10f($case), caseUser(Role::Cmo));

    expect($second->already)->toBeTrue()->and($second->by)->toBe('Closing Cmo')
        ->and([(array) row10f($case), transitions10f($case)->count(), DB::table('audit_logs')->where('case_id', $case->id)->count()])->toEqual($before);

    // Someone without letter.issue still gets 403, not the friendly message.
    $this->actingAs(caseUser(Role::PsOffice))->post(route('cases.close', $case))->assertForbidden();
});

it('changes nothing when the close audit fails', function () {
    $case = case10f(CaseStatus::LETTER_ISSUED);
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs') && in_array('case.closed', $query->bindings, true)) {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => app(CloseCase::class)->handle(fresh10f($case), caseUser(Role::Cmo)))->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(row10f($case))->status->toBe('LETTER_ISSUED')->closed_at->toBeNull()
        ->and(transitions10f($case))->toHaveCount(0);
});

it('refuses T14 when no letter is on record', function () {
    $case = case10f(CaseStatus::LETTER_ISSUED, withLetter: false);

    expect(fn () => app(CaseTransitionService::class)->transition(fresh10f($case), CaseStatus::CLOSED, caseUser(Role::Cmo)))
        ->toThrow(GateNotPassed::class);

    $errors = [];
    try {
        app(CloseCase::class)->handle(fresh10f($case), caseUser(Role::Cmo));
    } catch (ValidationException $e) {
        $errors = $e->errors();
    }

    expect($errors)->toHaveKey('close_case')
        ->and(row10f($case))->status->toBe('LETTER_ISSUED')->closed_at->toBeNull();
});

it('leaves CLOSED with no outgoing rules and no actions for anyone', function (string $role) {
    $case = case10f(CaseStatus::CLOSED);
    $actor = matrixActor10f($role, $case);

    expect(collect(TransitionRules::all())->filter(fn ($r) => $r->appliesFrom(CaseStatus::CLOSED)))->toBeEmpty();

    $can = Arr::except(StaffCaseDetail::for(fresh10f($case), $actor)['can'], ['requests', 'cases', 'download_documents']);
    expect(array_filter($can))->toBe([]);
})->with(['cmo', 'ps_office', 'cmo_registry', 'legal_officer']);

// 5. Overview (staff) ------------------------------------------------------------

it('shows the letter line to staff at LETTER_ISSUED and CLOSED only', function (string $statusValue, bool $shown) {
    $case = case10f(CaseStatus::from($statusValue));
    $recorder = User::find(DB::table('cases')->where('id', $case->id)->value('letter_recorded_by'));

    $letter = StaffCaseDetail::for(fresh10f($case), caseUser(Role::PsOffice))['letter'];

    if ($shown) {
        expect($letter)->toMatchArray([
            'date' => LETTER_DATE_10F, 'reference' => 'MOH/NOL/10F', 'emailed_on' => EMAILED_10F,
            'hardcopy_sent_on' => null, 'recorded_by' => $recorder->name,
        ])->and($letter['recorded_at'])->not->toBeNull();
    } else {
        expect($letter)->toBeNull();
    }
})->with([
    ['NON_OBJECTION_GRANTED', false],
    ['LETTER_ISSUED', true],
    ['CLOSED', true],
]);

// 6. Vendor ----------------------------------------------------------------------

/** Vendor detail props without the random or auth shared props. */
function vendorView10f($test, ProcurementCase $case): array
{
    $vendor = vendorUser($case->organisation()->first());

    return collect(pageProps($test->actingAs($vendor)->get(route('requests.show', $case))->assertOk()))
        ->except(['auth', 'flash', 'errors', 'quote'])->all(); // quote: the starter kit's random shared prop
}

it('still shows only "Under review" at WITH_PS and NON_OBJECTION_GRANTED', function () {
    $case = case10f(CaseStatus::WITH_PS);
    $strip = fn (array $props) => [...$props, 'case' => Arr::except($props['case'], ['updated_at'])];

    $before = vendorView10f($this, $case);
    app(RecordPsDecision::class)->handle(fresh10f($case), caseUser(Role::PsOffice), RecordPsDecision::GRANT);
    $after = vendorView10f($this, $case);

    expect($strip($after))->toBe($strip($before))
        ->and($after['case']['status_label'])->toBe('Under review')
        ->and($after['case']['letter'])->toBeNull();
});

it('shows the vendor the outcome with the letter date and reference only', function () {
    $case = case10f(CaseStatus::NON_OBJECTION_GRANTED);
    $cmo = caseUser(Role::Cmo, ['name' => 'Leaky Letter Cmo']);
    issue10f($case, $cmo, ['letter_reference' => 'MOH/NOL/77', 'hardcopy_sent_on' => HARDCOPY_10F]);

    foreach (['issued', 'closed'] as $stage) {
        if ($stage === 'closed') {
            app(CloseCase::class)->handle(fresh10f($case), $cmo);
        }

        $props = vendorView10f($this, $case);
        expect($props['case']['status_label'])->toBe('Non-objection granted')
            ->and($props['case']['letter'])->toBe(['date' => LETTER_DATE_10F, 'reference' => 'MOH/NOL/77']);

        expectVendorSafe($props);
        $scan = scanProps($props);
        foreach ($scan['strings'] as $string) {
            expect($string)->not->toContain(EMAILED_10F)
                ->and($string)->not->toContain(HARDCOPY_10F)
                ->and($string)->not->toContain('Leaky Letter Cmo')
                ->and($string)->not->toContain('HE:8/2/50');
        }

        $list = pageProps($this->actingAs(vendorUser($case->organisation()->first()))->get(route('requests.index'))->assertOk());
        $row = collect($list['requests']['data'])->firstWhere('id', $case->id);
        expect($row['status_label'])->toBe('Non-objection granted')
            ->and($row['status_group'])->toBe($stage === 'issued' ? 'LETTER_ISSUED' : 'CLOSED');
    }
});

it('labels LETTER_ISSUED and CLOSED "Non-objection granted" and keeps NON_OBJECTION_GRANTED "Under review"', function () {
    expect(CaseStatus::LETTER_ISSUED->vendorLabel())->toBe('Non-objection granted')
        ->and(CaseStatus::CLOSED->vendorLabel())->toBe('Non-objection granted')
        ->and(CaseStatus::LETTER_ISSUED->vendorGroup())->toBe('LETTER_ISSUED')
        ->and(CaseStatus::CLOSED->vendorGroup())->toBe('CLOSED')
        ->and(CaseStatus::NON_OBJECTION_GRANTED->vendorLabel())->toBe(CaseStatus::WITH_PS->vendorLabel());
});
