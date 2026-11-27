<?php

use App\Enums\CaseStatus;
use App\Enums\ReviewDesk;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Http\Resources\Cases\StaffCaseDetail;
use App\Models\CaseAssignment;
use App\Models\CaseTrackEvent;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\MarkReadyForFile;
use App\Workflow\Actions\PrepareFile;
use App\Workflow\Actions\RecordLegalDecision;
use App\Workflow\Actions\RecordPsDecision;
use App\Workflow\Actions\RecordTechnicalReview;
use App\Workflow\Actions\RouteReturnedCase;
use App\Workflow\Actions\SendToLegal;
use App\Workflow\CaseTransitionService;
use App\Workflow\Exceptions\GateNotPassed;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Slice 10e: route a returned case onward (T11, T12, T17) and the Send onward
// panel. DatabaseTransactions only. Time travel keeps round-one events and
// round-two assignments from ever sharing a timestamp.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 13:00:00', 'UTC'));
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const NOTE_10E = 'Please look at the dosage table again.';

function row10eAssign(ProcurementCase $case, string $purpose, string $role, User $user): CaseAssignment
{
    $row = new CaseAssignment;
    $row->forceFill([
        'case_id' => $case->id, 'role' => $role, 'purpose' => $purpose,
        'user_id' => $user->id, 'assigned_at' => CarbonImmutable::now('UTC'),
    ])->save();

    return $row;
}

function event10e(ProcurementCase $case, ReviewDesk $desk, User $user, string $outcome): void
{
    (new CaseTrackEvent)->forceFill([
        'case_id' => $case->id, 'track' => $desk, 'outcome' => $outcome, 'reason' => null,
        'actor_id' => $user->id, 'created_at' => CarbonImmutable::now('UTC'),
    ])->save();
}

/**
 * A case returned to the CMO through the real decision actions, after a full
 * first round: one Technocrat (FAVOURABLE), one CAB officer (CONFIRMED), the
 * hardcopy, a file number and one Legal officer.
 *
 * @param  'legal'|'ps_legal'|'ps_technical'  $source
 * @return array{case: ProcurementCase, tech: User, cab: User, legal: User}
 */
function returned10e(string $source): array
{
    $case = makeCase([
        'status' => $source === 'legal' ? CaseStatus::WITH_LEGAL : CaseStatus::WITH_PS,
        'reference_no' => 'NR-10E-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(9),
        'technical_track' => TrackStatus::FAVOURABLE,
        'funding_track' => TrackStatus::CONFIRMED,
        'hardcopy_received_on' => CarbonImmutable::now('America/Port_of_Spain')->subDays(6)->toDateString(),
        'hardcopy_received_at' => CarbonImmutable::now('UTC')->subDays(6),
        'hardcopy_received_by' => caseUser(Role::CmoRegistry)->id,
        'file_no' => 'HE:8/2/50/10E',
    ]);
    $tech = caseUser(Role::Technocrat, ['name' => 'Tech '.Str::upper(Str::random(6))]);
    $cab = caseUser(Role::CabOfficer, ['name' => 'Cab '.Str::upper(Str::random(6))]);
    $legal = caseUser(Role::LegalOfficer, ['name' => 'Legal '.Str::upper(Str::random(6))]);

    row10eAssign($case, ReviewDesk::Technical->value, ReviewDesk::Technical->assignmentRole(), $tech);
    row10eAssign($case, ReviewDesk::Funding->value, ReviewDesk::Funding->assignmentRole(), $cab);
    row10eAssign($case, SendToLegal::PURPOSE, SendToLegal::assignmentRole(), $legal);
    test()->travel(5)->minutes();
    event10e($case, ReviewDesk::Technical, $tech, 'favourable');
    event10e($case, ReviewDesk::Funding, $cab, 'confirmed');
    test()->travel(1)->hours();

    match ($source) {
        'legal' => app(RecordLegalDecision::class)->handle($case, $legal, 'return_to_cmo', 'Clause 4 needs a technical answer.'),
        'ps_legal' => app(RecordPsDecision::class)->handle($case, caseUser(Role::PsOffice), 'return_to_cmo', 'Clause 9 needs Legal again.', 'legal'),
        'ps_technical' => app(RecordPsDecision::class)->handle($case, caseUser(Role::PsOffice), 'return_to_cmo', 'Dosage needs a technical answer.', 'technical'),
    };
    test()->travel(1)->hours();

    return ['case' => fresh10e($case), 'tech' => $tech, 'cab' => $cab, 'legal' => $legal];
}

function fresh10e(ProcurementCase $case): ProcurementCase
{
    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

function row10e(ProcurementCase $case): object
{
    return DB::table('cases')->where('id', $case->id)->first();
}

function transitions10e(ProcurementCase $case): Collection
{
    return DB::table('case_transitions')->where('case_id', $case->id)->orderBy('id')->get();
}

function techRows10e(ProcurementCase $case): Collection
{
    return DB::table('case_assignments')->where('case_id', $case->id)->where('purpose', 'technical')->orderBy('id')->get();
}

function route10e(ProcurementCase $case, string $destination, array $reviewers = [], ?string $note = NOTE_10E, ?User $actor = null)
{
    return app(RouteReturnedCase::class)->handle(fresh10e($case), $actor ?? caseUser(Role::Cmo), $destination, $note, $reviewers);
}

/** @return array<string, string> the validation errors of $call */
function errors10e(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return array_map(fn (array $m) => $m[0], $e->errors());
    }

    return [];
}

// 1. Policy matrix ---------------------------------------------------------------

const ROLES_10E = ['cmo', 'cmo_registry', 'technocrat', 'technocrat_unassigned', 'cab_officer', 'legal_assigned', 'ps_office', 'system_admin', 'auditor', 'vendor'];

const STATUSES_10E = ['DRAFT', 'IN_REVIEW', 'WITH_LEGAL', 'WITH_PS', 'RETURNED_TO_CMO', 'CLOSED'];

/** A case at $status; at RETURNED_TO_CMO with a Legal return on record. A Legal officer is assigned. */
function matrixCase10e(CaseStatus $status): array
{
    $case = makeCase(['status' => $status, 'reference_no' => 'NR-10E-'.Str::upper(Str::random(8)), 'submitted_at' => CarbonImmutable::now('UTC')->subDays(3)]);
    $legal = caseUser(Role::LegalOfficer);
    row10eAssign($case, SendToLegal::PURPOSE, SendToLegal::assignmentRole(), $legal);

    if ($status === CaseStatus::RETURNED_TO_CMO) {
        (new CaseTransition)->forceFill([
            'case_id' => $case->id, 'from_status' => CaseStatus::WITH_LEGAL, 'to_status' => CaseStatus::RETURNED_TO_CMO,
            'actor_id' => $legal->id, 'reason' => 'Clause 4 needs work.', 'created_at' => CarbonImmutable::now('UTC'),
        ])->save();
    }
    test()->travel(1)->minutes();

    return [$case, $legal];
}

function matrixActor10e(string $role, ProcurementCase $case, User $legal): User
{
    return match ($role) {
        'legal_assigned' => $legal,
        'technocrat_unassigned' => caseUser(Role::Technocrat),
        'vendor' => vendorUser($case->organisation()->first()),
        'cmo_registry', 'technocrat', 'cab_officer' => tap(caseUser($role), fn (User $u) => assign($case, $role, $u)),
        default => caseUser($role),
    };
}

/** @return 'ok'|'error'|'forbidden'|'notfound' */
function expected10e(string $role, CaseStatus $status): string
{
    return match (true) {
        $role === 'vendor' => 'forbidden',                                                         // route middleware
        in_array($role, ['system_admin', 'auditor', 'technocrat_unassigned'], true) => 'notfound',   // cannot see it
        $status === CaseStatus::DRAFT => 'notfound',                                                // staff never see drafts
        $role !== 'cmo' => 'forbidden',                                                             // no query.forward
        $status->isTerminal() => 'forbidden',                                                       // act() refuses terminal cases
        $status === CaseStatus::RETURNED_TO_CMO => 'ok',
        default => 'error',
    };
}

it('allows routing onward exactly as the policy matrix says', function (string $role, string $statusValue) {
    $status = CaseStatus::from($statusValue);
    [$case, $legal] = matrixCase10e($status);
    $actor = matrixActor10e($role, $case, $legal);
    $before = transitions10e($case)->count();

    $response = $this->actingAs($actor)->post(route('cases.route.onward', $case), ['destination' => 'legal', 'note' => NOTE_10E]);
    $expected = expected10e($role, $status);

    match ($expected) {
        'ok' => $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success'),
        'error' => $response->assertRedirect()->assertSessionHasErrors('route'),
        'forbidden' => $response->assertForbidden(),
        'notfound' => $response->assertNotFound(),
    };

    expect(row10e($case)->status)->toBe($expected === 'ok' ? 'WITH_LEGAL' : $status->value)
        ->and(transitions10e($case))->toHaveCount($before + ($expected === 'ok' ? 1 : 0));
})->with(ROLES_10E)->with(STATUSES_10E);

it('gives only the CMO the Send onward flag and panel', function (string $role) {
    ['case' => $case, 'legal' => $legal] = returned10e('legal');
    $actor = matrixActor10e($role, $case, $legal);

    $props = StaffCaseDetail::for(fresh10e($case), $actor);

    expect($props['can']['route_onward'])->toBe($role === 'cmo')
        ->and($props['route_panel'] === null)->toBe($role !== 'cmo');
})->with(['cmo', 'cmo_registry', 'technocrat', 'cab_officer', 'legal_assigned', 'ps_office']);

// 2. Destination matrix ----------------------------------------------------------

it('allows destinations by source desk', function (string $source, string $destination, bool $allowed) {
    ['case' => $case, 'tech' => $tech] = returned10e($source);
    $reviewers = $destination === 'technocrats' ? [(int) $tech->id] : [];

    $errors = errors10e(fn () => route10e($case, $destination, $reviewers));

    if ($allowed) {
        expect($errors)->toBe([])
            ->and(row10e($case)->status)->toBe(RouteReturnedCase::targetStatus($destination)->value);
    } else {
        expect($errors)->toHaveKey('destination')
            ->and($errors['destination'])->toBe('Only a case returned by the PS can go back to the PS.')
            ->and(row10e($case)->status)->toBe('RETURNED_TO_CMO');
    }
})->with([
    'legal -> technocrats' => ['legal', 'technocrats', true],
    'legal -> legal' => ['legal', 'legal', true],
    'legal -> ps' => ['legal', 'ps', false],
    'ps legal -> technocrats' => ['ps_legal', 'technocrats', true],
    'ps legal -> legal' => ['ps_legal', 'legal', true],
    'ps legal -> ps' => ['ps_legal', 'ps', true],
    'ps technical -> technocrats' => ['ps_technical', 'technocrats', true],
    'ps technical -> legal' => ['ps_technical', 'legal', true],
    'ps technical -> ps' => ['ps_technical', 'ps', true],
]);

it('blocks T17 for a Legal return even through the service directly', function () {
    ['case' => $case] = returned10e('legal');

    expect(fn () => app(CaseTransitionService::class)->transition(fresh10e($case), CaseStatus::WITH_PS, caseUser(Role::Cmo), NOTE_10E))
        ->toThrow(GateNotPassed::class);

    expect(row10e($case)->status)->toBe('RETURNED_TO_CMO')
        ->and(TransitionRules::GUARD_MESSAGES['returned_by_ps'])->toBe('Only a case returned by the PS can go back to the PS');
});

it('requires a reason on T12 and T17 and keeps T11 as it was', function () {
    $find = fn (string $code) => collect(TransitionRules::all())->firstWhere('code', $code);

    expect($find('T12')->requiresReason)->toBeTrue()->and($find('T12')->pendingGuards)->toBe([])
        ->and($find('T17')->requiresReason)->toBeTrue()->and($find('T17')->pendingGuards)->toBe([])->and($find('T17')->guard)->not->toBeNull()
        ->and($find('T11')->requiresReason)->toBeTrue()->and($find('T11')->resetTracks)->toBe(['technical_track']);
});

// 3. Note ------------------------------------------------------------------------

it('requires a note of 10 to 500 characters for every destination', function (string $destination, ?string $note, bool $ok) {
    ['case' => $case, 'tech' => $tech] = returned10e('ps_technical');
    $payload = ['destination' => $destination, 'note' => $note, 'reviewers' => $destination === 'technocrats' ? [$tech->id] : []];

    $response = $this->actingAs(caseUser(Role::Cmo))->post(route('cases.route.onward', $case), $payload);

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('note');
    expect(row10e($case)->status)->toBe($ok ? RouteReturnedCase::targetStatus($destination)->value : 'RETURNED_TO_CMO');
})->with(['technocrats', 'legal', 'ps'])->with([
    'missing' => [null, false],
    'blank' => ['            ', false],
    '9 characters' => ['123456789', false],
    '9 characters padded' => ['   123456789   ', false],
    '501 characters' => [str_repeat('a', 501), false],
    '10 characters' => ['1234567890', true],
    '500 characters' => [str_repeat('a', 500), true],
]);

it('stores the note as the transition reason', function () {
    ['case' => $case] = returned10e('legal');

    route10e($case, 'legal', [], '   '.NOTE_10E.'   ');

    expect(transitions10e($case)->last())
        ->from_status->toBe('RETURNED_TO_CMO')
        ->to_status->toBe('WITH_LEGAL')
        ->reason->toBe(NOTE_10E);
});

// 4. T11 -------------------------------------------------------------------------

it('validates the Technocrats chosen for T11', function (Closure $ids, string $message) {
    ['case' => $case] = returned10e('legal');
    $before = techRows10e($case)->count();

    $errors = errors10e(fn () => route10e($case, 'technocrats', $ids()));

    expect($errors['reviewers'] ?? null)->toContain($message)
        ->and(row10e($case)->status)->toBe('RETURNED_TO_CMO')
        ->and(techRows10e($case))->toHaveCount($before);
})->with([
    'none' => [fn () => [], 'Choose 1 to 3 Technocrats.'],
    'four' => [fn () => array_map(fn () => (int) caseUser(Role::Technocrat)->id, range(1, 4)), 'Choose 1 to 3 Technocrats.'],
    'inactive' => [fn () => [(int) caseUser(Role::Technocrat, ['status' => 'disabled'])->id], 'Choose from the listed Technocrats.'],
    'wrong permission' => [fn () => [(int) caseUser(Role::CabOfficer)->id], 'Choose from the listed Technocrats.'],
    'unknown id' => [fn () => [99999999], 'Choose from the listed Technocrats.'],
]);

it('refuses reviewer ids for Legal and PS destinations', function (string $destination) {
    ['case' => $case, 'tech' => $tech] = returned10e('ps_legal');

    expect(errors10e(fn () => route10e($case, $destination, [(int) $tech->id])))->toHaveKey('reviewers')
        ->and(row10e($case)->status)->toBe('RETURNED_TO_CMO');
})->with(['legal', 'ps']);

it('collapses duplicate Technocrat ids into one assignment', function () {
    ['case' => $case] = returned10e('legal');
    $new = caseUser(Role::Technocrat);

    route10e($case, 'technocrats', [(int) $new->id, (string) $new->id, (int) $new->id]);

    expect(techRows10e($case)->whereNull('completed_at')->pluck('user_id')->map(fn ($id) => (int) $id)->all())->toBe([(int) $new->id]);
});

it('starts a new technical round: old rows completed, new rows active, other desks untouched', function () {
    ['case' => $case, 'tech' => $tech] = returned10e('legal');
    $x = caseUser(Role::Technocrat);
    $y = caseUser(Role::Technocrat);
    $old = techRows10e($case)->sole();
    $others = DB::table('case_assignments')->where('case_id', $case->id)->where('purpose', '!=', 'technical')->orderBy('id')->get();
    $hardcopy = Arr::only((array) row10e($case), ['hardcopy_received_on', 'hardcopy_received_at', 'hardcopy_received_by']);

    route10e($case, 'technocrats', [(int) $x->id, (int) $y->id]);

    $rows = techRows10e($case);
    $first = $rows->firstWhere('id', $old->id);
    expect($rows)->toHaveCount(3)
        ->and($first->completed_at)->not->toBeNull()
        ->and(Arr::except((array) $first, ['completed_at', 'updated_at']))->toBe(Arr::except((array) $old, ['completed_at', 'updated_at']))
        ->and($rows->whereNull('completed_at')->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe(collect([(int) $x->id, (int) $y->id])->sort()->values()->all())
        ->and($rows->whereNull('completed_at')->pluck('role')->unique()->all())->toBe([ReviewDesk::Technical->assignmentRole()])
        ->and(DB::table('case_assignments')->where('case_id', $case->id)->where('purpose', '!=', 'technical')->orderBy('id')->get())->toEqual($others);

    $row = row10e($case);
    expect($row->status)->toBe('IN_REVIEW')
        ->and($row->technical_track)->toBe('PENDING')
        ->and($row->funding_track)->toBe('CONFIRMED')
        ->and(Arr::only((array) $row, array_keys($hardcopy)))->toBe($hardcopy);
});

it('lets the chosen Technocrat see the case and hides it from one never assigned', function () {
    ['case' => $case] = returned10e('legal');
    $chosen = caseUser(Role::Technocrat);

    route10e($case, 'technocrats', [(int) $chosen->id]);

    $this->actingAs($chosen)->get(route('cases.show', $case))->assertOk();
    $this->actingAs(caseUser(Role::Technocrat))->get(route('cases.show', $case))->assertNotFound();
});

it('keeps the first-round Technocrat read-only: sees the case, cannot record an outcome', function () {
    ['case' => $case, 'tech' => $tech] = returned10e('legal');

    route10e($case, 'technocrats', [(int) caseUser(Role::Technocrat)->id]);
    $events = DB::table('case_track_events')->where('case_id', $case->id)->count();

    $props = pageProps($this->actingAs($tech)->get(route('cases.show', $case))->assertOk());
    expect($props['can']['record_review'])->toBeFalse()->and($props['review_forms'])->toBe([]);

    $this->actingAs($tech)->post(route('cases.technical', $case), ['outcome' => 'favourable'])->assertForbidden();
    expect(DB::table('case_track_events')->where('case_id', $case->id)->count())->toBe($events)
        ->and(row10e($case)->technical_track)->toBe('PENDING');
});

it('does not count a stale FAVOURABLE; a new reviewer\'s outcome completes the round', function () {
    ['case' => $case, 'tech' => $tech] = returned10e('legal');
    $x = caseUser(Role::Technocrat);

    route10e($case, 'technocrats', [(int) $x->id, (int) $tech->id]);
    $this->travel(1)->hours();
    app(RecordTechnicalReview::class)->handle(fresh10e($case), $x, 'favourable');

    // Kyle, 2026-11-26: one officer's outcome completes the desk; the re-chosen
    // reviewer's round-one FAVOURABLE still does not count for them.
    expect(row10e($case)->technical_track)->toBe('FAVOURABLE');

    $panel = StaffCaseDetail::for(fresh10e($case), caseUser(Role::Cmo))['gate']['rows'][0]['reviewers'];
    expect(collect($panel)->firstWhere('name', $tech->name)['outcome'])->toBeNull();

    $this->travel(1)->minutes();
    app(RecordTechnicalReview::class)->handle(fresh10e($case), $tech, 'favourable');
    expect(row10e($case)->technical_track)->toBe('FAVOURABLE');
});

it('makes the track FAVOURABLE on the new reviewer\'s outcome', function () {
    ['case' => $case] = returned10e('ps_technical');
    $x = caseUser(Role::Technocrat);

    route10e($case, 'technocrats', [(int) $x->id]);
    $this->travel(1)->hours();
    app(RecordTechnicalReview::class)->handle(fresh10e($case), $x, 'favourable');

    expect(row10e($case)->technical_track)->toBe('FAVOURABLE')
        ->and(TransitionRules::gateMissing(fresh10e($case)))->toBe([]);
});

// 5. Full loop -------------------------------------------------------------------

it('runs the full loop from a Legal return back to WITH_PS', function () {
    ['case' => $case, 'legal' => $legal] = returned10e('legal');
    $cmo = caseUser(Role::Cmo);
    $x = caseUser(Role::Technocrat);

    route10e($case, 'technocrats', [(int) $x->id], NOTE_10E, $cmo);
    $this->travel(1)->hours();
    app(RecordTechnicalReview::class)->handle(fresh10e($case), $x, 'favourable');
    $this->travel(1)->minutes();
    app(MarkReadyForFile::class)->handle(fresh10e($case), $cmo);

    $default = StaffCaseDetail::for(fresh10e($case), $cmo)['file_no_default'];
    expect($default)->toBe('HE:8/2/50/10E');

    app(PrepareFile::class)->handle(fresh10e($case), $cmo, $default);
    app(SendToLegal::class)->handle(fresh10e($case), $cmo, [(int) $legal->id]);
    $this->travel(1)->minutes();
    app(RecordLegalDecision::class)->handle(fresh10e($case), $legal, 'confirmed');

    expect(row10e($case))->status->toBe('WITH_PS')->file_no->toBe('HE:8/2/50/10E')
        ->and(transitions10e($case)->pluck('to_status')->all())
        ->toBe(['RETURNED_TO_CMO', 'IN_REVIEW', 'READY_FOR_FILE', 'FILE_PREPARED', 'WITH_LEGAL', 'WITH_PS']);
});

// 6. T12 and T17 -----------------------------------------------------------------

it('sends a case back to Legal, where the still-assigned officer can decide again', function () {
    ['case' => $case, 'legal' => $legal] = returned10e('legal');

    route10e($case, 'legal');
    expect(row10e($case)->status)->toBe('WITH_LEGAL')
        ->and(row10e($case)->current_holder_role)->toBe('legal_officer');

    $this->travel(1)->minutes();
    $second = app(RecordLegalDecision::class)->handle(fresh10e($case), $legal, 'confirmed');
    expect($second->already)->toBeFalse()->and(row10e($case)->status)->toBe('WITH_PS');
});

it('refuses T12 when no Legal officer is still assigned, and changes nothing', function () {
    ['case' => $case] = returned10e('ps_legal');
    DB::table('case_assignments')->where('case_id', $case->id)->where('purpose', 'legal')->update(['completed_at' => CarbonImmutable::now('UTC')]);
    $before = [transitions10e($case)->count(), DB::table('case_assignments')->where('case_id', $case->id)->get()->toArray()];

    $errors = errors10e(fn () => route10e($case, 'legal'));

    expect($errors)->toBe(['route' => 'No Legal officer is assigned to this case.'])
        ->and(row10e($case)->status)->toBe('RETURNED_TO_CMO')
        ->and([transitions10e($case)->count(), DB::table('case_assignments')->where('case_id', $case->id)->get()->toArray()])->toEqual($before);

    $panel = StaffCaseDetail::for(fresh10e($case), caseUser(Role::Cmo))['route_panel'];
    expect(collect($panel['destinations'])->firstWhere('value', 'legal')['available'])->toBeFalse()
        ->and($panel['suggested'])->not->toBe('legal');
});

it('sends a PS return back to the PS, who can decide again', function (string $source) {
    ['case' => $case] = returned10e($source);
    $assignments = DB::table('case_assignments')->where('case_id', $case->id)->count();

    route10e($case, 'ps');
    expect(row10e($case))->status->toBe('WITH_PS')->current_holder_role->toBe('ps_office')
        ->and(DB::table('case_assignments')->where('case_id', $case->id)->count())->toBe($assignments);

    $this->travel(1)->minutes();
    $second = app(RecordPsDecision::class)->handle(fresh10e($case), caseUser(Role::PsOffice), 'grant');
    expect($second->already)->toBeFalse()->and(row10e($case)->status)->toBe('NON_OBJECTION_GRANTED');
})->with(['ps_legal', 'ps_technical']);

// 7. Already routed and fail closed ----------------------------------------------

it('names who routed it and where on a second call, adding nothing', function () {
    ['case' => $case] = returned10e('legal');
    $first = caseUser(Role::Cmo, ['name' => 'First Cmo']);
    route10e($case, 'technocrats', [(int) caseUser(Role::Technocrat)->id], NOTE_10E, $first);
    $counts = fn () => [
        transitions10e($case)->count(),
        DB::table('case_assignments')->where('case_id', $case->id)->count(),
        DB::table('audit_logs')->where('case_id', $case->id)->count(),
    ];
    $before = $counts();

    $second = app(RouteReturnedCase::class)->handle(fresh10e($case), caseUser(Role::Cmo), 'legal', NOTE_10E, []);

    expect($second->already)->toBeTrue()
        ->and($second->by)->toBe('First Cmo')
        ->and($second->detail)->toBe(CaseStatus::IN_REVIEW->label())
        ->and($counts())->toBe($before);

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.route.onward', $case), ['destination' => 'legal', 'note' => NOTE_10E])
        ->assertSessionHas('info', fn (string $m) => str_contains($m, 'already sent onward') && str_contains($m, 'First Cmo'));
});

it('changes nothing when the routed-onward audit fails', function (string $destination) {
    ['case' => $case, 'tech' => $tech] = returned10e('ps_technical');
    $snapshot = fn () => [
        (array) row10e($case),
        transitions10e($case)->toArray(),
        DB::table('case_assignments')->where('case_id', $case->id)->orderBy('id')->get()->toArray(),
    ];
    $before = $snapshot();
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs') && in_array('case.routed_onward', $query->bindings, true)) {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => route10e($case, $destination, $destination === 'technocrats' ? [(int) caseUser(Role::Technocrat)->id] : []))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect($snapshot())->toEqual($before);
})->with(['technocrats', 'legal', 'ps']);

// 8. Audit -----------------------------------------------------------------------

it('audits counts and note length, never the note text', function () {
    ['case' => $case] = returned10e('legal');
    $note = 'Quokka-ember note 5512: must never reach audit_logs';
    $x = caseUser(Role::Technocrat);

    route10e($case, 'technocrats', [(int) $x->id], $note);

    $audits = DB::table('audit_logs')->where('case_id', $case->id)->get();
    $routed = json_decode($audits->where('action', 'case.routed_onward')->sole()->metadata, true);

    expect($routed)->toBe([
        'destination' => 'technocrats', 'rule' => 'T11', 'reviewers' => 1, 'reviewers_completed' => 1,
        'legal_officers' => 0, 'has_note' => true, 'note_length' => mb_strlen($note),
    ])
        ->and($audits->filter(fn ($row) => str_contains((string) $row->metadata, 'Quokka-ember')))->toBeEmpty()
        ->and(transitions10e($case)->last()->reason)->toBe($note)
        ->and($audits->where('action', 'case.assignment_completed'))->toHaveCount(1)
        ->and($audits->where('action', 'case.assignment_created'))->toHaveCount(1);
});

// 9. Panel props -----------------------------------------------------------------

it('offers only the allowed destinations and the suggested one', function (string $source, array $values, string $suggested, string $line) {
    ['case' => $case] = returned10e($source);

    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk());
    $panel = $props['route_panel'];

    expect($props['can']['route_onward'])->toBeTrue()
        ->and(array_column($panel['destinations'], 'value'))->toBe($values)
        ->and($panel['suggested'])->toBe($suggested)
        ->and($panel['suggested_line'])->toBe($line)
        ->and(array_keys($panel))->toBe(['destinations', 'suggested', 'suggested_line', 'technocrats', 'reviewers_min', 'reviewers_max', 'note_min', 'note_max', 'timeline'])
        ->and(array_keys($panel['destinations'][0]))->toBe(['value', 'label', 'consequence', 'available', 'button'])
        ->and(array_keys($panel['technocrats'][0] ?? ['id' => 0, 'name' => '']))->toBe(['id', 'name'])
        ->and(count($panel['timeline']))->toBeLessThanOrEqual(8)
        ->and($panel['timeline'][0]['to_label'])->toBe(CaseStatus::RETURNED_TO_CMO->label());

    $json = json_encode($panel);
    foreach (['unit_price', 'quotation', 'amount', 'supplier', 'offered', 'budget'] as $word) {
        expect(str_contains(strtolower($json), $word))->toBeFalse();
    }
})->with([
    'Legal return' => ['legal', ['technocrats', 'legal'], 'technocrats', 'Suggested: Technocrats'],
    'PS legal-related' => ['ps_legal', ['technocrats', 'legal', 'ps'], 'legal', 'Suggested: Legal Unit'],
    'PS technical' => ['ps_technical', ['technocrats', 'legal', 'ps'], 'technocrats', 'Suggested: Technocrats'],
]);

it('limits the panel history to the last 8 rows, newest first', function () {
    ['case' => $case] = returned10e('ps_technical');
    for ($i = 0; $i < 9; $i++) {
        (new CaseTransition)->forceFill([
            'case_id' => $case->id, 'from_status' => CaseStatus::IN_REVIEW, 'to_status' => CaseStatus::IN_REVIEW,
            'actor_id' => caseUser(Role::Cmo)->id, 'reason' => "Filler {$i}", 'created_at' => CarbonImmutable::now('UTC')->subDays(20 - $i),
        ])->save();
    }

    $timeline = StaffCaseDetail::for(fresh10e($case), caseUser(Role::Cmo))['route_panel']['timeline'];
    $all = StaffCaseDetail::for(fresh10e($case), caseUser(Role::Cmo))['timeline'];

    expect($timeline)->toHaveCount(8)
        ->and($timeline)->toBe(array_slice(array_reverse($all), 0, 8));
});

// 10. Vendor ---------------------------------------------------------------------

it('gives IN_REVIEW, WITH_LEGAL and WITH_PS the same vendor label', function () {
    expect(CaseStatus::WITH_LEGAL->vendorLabel())->toBe(CaseStatus::IN_REVIEW->vendorLabel())
        ->and(CaseStatus::WITH_PS->vendorLabel())->toBe(CaseStatus::IN_REVIEW->vendorLabel())
        ->and(CaseStatus::RETURNED_TO_CMO->vendorGroup())->toBe(CaseStatus::IN_REVIEW->vendorGroup());
});

it('shows the vendor nothing of the routing', function (string $source, string $destination) {
    ['case' => $case] = returned10e($source);
    $vendor = vendorUser($case->organisation()->first());
    $view = fn () => collect(pageProps($this->actingAs($vendor)->get(route('requests.show', $case))->assertOk()))->except(['auth', 'flash', 'errors', 'quote'])->all(); // quote: the starter kit's random shared prop
    $reviewer = caseUser(Role::Technocrat, ['name' => 'Leaky Reviewer Name']);

    $before = $view();
    route10e($case, $destination, $destination === 'technocrats' ? [(int) $reviewer->id] : [], 'Routing note must not leak to vendor');
    $after = $view();

    $strip = fn (array $props) => [...$props, 'case' => Arr::except($props['case'], ['updated_at'])];
    expect($strip($after))->toBe($strip($before));

    expectVendorSafe($after);
    $scan = scanProps($after);
    foreach (['note', 'reason', 'source', 'kind', 'destination', 'reviewers', 'route_panel', 'returned'] as $key) {
        expect($scan['keys'])->not->toContain($key);
    }
    foreach ($scan['strings'] as $string) {
        expect($string)->not->toContain('Leaky Reviewer Name')
            ->and($string)->not->toContain('must not leak')
            ->and($string)->not->toContain('PS office')
            ->and($string)->not->toContain('legal-related')
            ->and($string)->not->toContain('technical query');
    }
})->with([
    'Legal return to Technocrats' => ['legal', 'technocrats'],
    'PS legal-related to Legal' => ['ps_legal', 'legal'],
    'PS technical to PS' => ['ps_technical', 'ps'],
]);

// 11. PrepareFile default --------------------------------------------------------

it('pre-fills the existing file number, or the config prefix when there is none', function (?string $fileNo, string $expected) {
    $case = makeCase([
        'status' => CaseStatus::READY_FOR_FILE,
        'reference_no' => 'NR-10E-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(3),
        'file_no' => $fileNo,
    ]);

    expect(StaffCaseDetail::for(fresh10e($case), caseUser(Role::Cmo))['file_no_default'])->toBe($expected);
})->with([
    'existing' => ['HE:8/2/50/77', 'HE:8/2/50/77'],
    'none' => [null, 'HE:8/2/50'],
]);
