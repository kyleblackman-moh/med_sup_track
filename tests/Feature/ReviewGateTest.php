<?php

use App\Enums\CaseStatus;
use App\Enums\ReviewDesk;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Models\CaseAssignment;
use App\Models\CaseTrackEvent;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\MarkReadyForFile;
use App\Workflow\Actions\RecordFundingConfirmation;
use App\Workflow\Actions\RecordTechnicalReview;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Slice 10b: reviewer outcomes and the 3-tick gate (T4). DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** An active reviewer assignment as ForwardForReview (10a) creates it. */
function reviewerRow10b(ProcurementCase $case, ReviewDesk $desk, User $user): CaseAssignment
{
    $row = new CaseAssignment;
    $row->forceFill([
        'case_id' => $case->id,
        'role' => $desk->assignmentRole(),
        'purpose' => $desk->value,
        'user_id' => $user->id,
        'assigned_at' => now(),
    ])->save();

    return $row;
}

/**
 * An IN_REVIEW case with one technocrat and one CAB officer assigned and both
 * tracks PENDING. Returns [case, technocrat, cab].
 *
 * @return array{0: ProcurementCase, 1: User, 2: User}
 */
function reviewCase10b(array $attributes = []): array
{
    $case = makeCase([
        'status' => CaseStatus::IN_REVIEW,
        'reference_no' => 'NR-10B-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(3),
        'technical_track' => TrackStatus::PENDING,
        'funding_track' => TrackStatus::PENDING,
        ...$attributes,
    ]);
    $tech = caseUser(Role::Technocrat, ['name' => 'Tech '.Str::upper(Str::random(5))]);
    $cab = caseUser(Role::CabOfficer, ['name' => 'Cab '.Str::upper(Str::random(5))]);
    reviewerRow10b($case, ReviewDesk::Technical, $tech);
    reviewerRow10b($case, ReviewDesk::Funding, $cab);

    return [$case, $tech, $cab];
}

/** Sets the three gate conditions directly (test setup only). */
function setGate10b(ProcurementCase $case, bool $technical, bool $funding, bool $hardcopy): void
{
    DB::table('cases')->where('id', $case->id)->update([
        'technical_track' => $technical ? 'FAVOURABLE' : 'PENDING',
        'funding_track' => $funding ? 'CONFIRMED' : 'PENDING',
        // All three hardcopy columns together (slice 10c CHECK constraint).
        'hardcopy_received_at' => $hardcopy ? CarbonImmutable::now('UTC') : null,
        'hardcopy_received_by' => $hardcopy ? caseUser(Role::CmoRegistry)->id : null,
        'hardcopy_received_on' => $hardcopy ? CarbonImmutable::now('America/Port_of_Spain')->toDateString() : null,
    ]);
}

function row10b(ProcurementCase $case): object
{
    return DB::table('cases')->where('id', $case->id)->first();
}

function events10b(ProcurementCase $case): int
{
    return DB::table('case_track_events')->where('case_id', $case->id)->count();
}

function fresh10b(ProcurementCase $case): ProcurementCase
{
    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

// 1. Policy matrix ---------------------------------------------------------------

const ROLES_10B = ['cmo', 'cmo_registry', 'technocrat_assigned', 'technocrat_unassigned', 'cab_assigned', 'legal_officer', 'ps_office', 'system_admin', 'auditor', 'vendor'];

const STATUSES_10B = ['DRAFT', 'SUBMITTED', 'CMO_INTAKE', 'IN_REVIEW', 'READY_FOR_FILE', 'WITH_PS', 'CLOSED', 'WITHDRAWN'];

/** The actor for a matrix role; staff who need an assignment to see the case get one. */
function matrixActor10b(string $role, ProcurementCase $case, User $tech, User $cab): User
{
    return match ($role) {
        'technocrat_assigned' => $tech,
        'cab_assigned' => $cab,
        'technocrat_unassigned' => caseUser(Role::Technocrat),
        'vendor' => vendorUser($case->organisation()->first()),
        'cmo_registry', 'legal_officer' => tap(caseUser($role), fn (User $u) => assign($case, $role, $u)),
        default => caseUser($role),
    };
}

/** @return 'ok'|'error'|'forbidden'|'notfound' */
function expected10b(string $action, string $role, CaseStatus $status): string
{
    $allowed = ['technical' => 'technocrat_assigned', 'funding' => 'cab_assigned', 'ready' => 'cmo'][$action];

    return match (true) {
        $role === 'vendor' => 'forbidden',                                              // route middleware
        in_array($role, ['system_admin', 'auditor', 'technocrat_unassigned'], true) => 'notfound', // cannot see it
        $status === CaseStatus::DRAFT => 'notfound',                                     // staff never see drafts
        $role !== $allowed => 'forbidden',                                               // permission (or desk) missing
        $status->isTerminal() => 'forbidden',                                            // act() refuses terminal cases
        $status === CaseStatus::IN_REVIEW => 'ok',
        default => 'error',                                                              // review closed / not in review
    };
}

it('allows each gate action exactly as the policy matrix says', function (string $action, string $role, string $statusValue) {
    $status = CaseStatus::from($statusValue);
    [$case, $tech, $cab] = reviewCase10b(['status' => $status]);
    if ($action === 'ready') {
        setGate10b($case, true, true, true);
    }
    $actor = matrixActor10b($role, $case, $tech, $cab);

    [$route, $payload, $errorKey] = match ($action) {
        'technical' => ['cases.technical', ['outcome' => 'favourable'], 'review'],
        'funding' => ['cases.funding', ['outcome' => 'confirmed'], 'review'],
        'ready' => ['cases.ready', [], 'gate'],
    };

    $response = $this->actingAs($actor)->post(route($route, $case), $payload);
    $expected = expected10b($action, $role, $status);

    match ($expected) {
        'ok' => $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success'),
        'error' => $response->assertRedirect()->assertSessionHasErrors($errorKey),
        'forbidden' => $response->assertForbidden(),
        'notfound' => $response->assertNotFound(),
    };

    $ok = $expected === 'ok';
    expect(events10b($case))->toBe($ok && $action !== 'ready' ? 1 : 0)
        ->and(row10b($case)->status)->toBe($ok && $action === 'ready' ? 'READY_FOR_FILE' : $status->value);
})->with(['technical', 'funding', 'ready'])->with(ROLES_10B)->with(STATUSES_10B);

it('refuses a reviewer whose assignment is completed, even with the permission', function () {
    [$case, $tech] = reviewCase10b();
    CaseAssignment::where('case_id', $case->id)->where('user_id', $tech->id)->update(['completed_at' => now()]);

    // Completed rows keep read-only visibility, so this is 403, not 404.
    $this->actingAs($tech)->post(route('cases.technical', $case), ['outcome' => 'favourable'])->assertForbidden();
    expect(events10b($case))->toBe(0);
});

// 2. Recording -------------------------------------------------------------------

it('records an outcome, inserts a history row and recalculates the track', function () {
    [$case, $tech] = reviewCase10b();

    $this->actingAs($tech)->post(route('cases.technical', $case), ['outcome' => 'favourable', 'reason' => 'Specs match.'])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Technical review recorded.');

    $event = DB::table('case_track_events')->where('case_id', $case->id)->first();
    expect($event->track)->toBe('technical')
        ->and($event->outcome)->toBe('favourable')
        ->and($event->reason)->toBe('Specs match.')
        ->and((int) $event->actor_id)->toBe($tech->id)
        ->and($event->created_at)->not->toBeNull()
        ->and(row10b($case)->technical_track)->toBe('FAVOURABLE')
        ->and(row10b($case)->funding_track)->toBe('PENDING')
        ->and(row10b($case)->status)->toBe('IN_REVIEW');
});

it('requires a reason for a negative outcome and none for a positive one', function (string $route, array $payload, ?string $errorKey) {
    [$case, $tech, $cab] = reviewCase10b();
    $actor = $route === 'cases.technical' ? $tech : $cab;

    $response = $this->actingAs($actor)->post(route($route, $case), $payload);

    if ($errorKey) {
        $response->assertSessionHasErrors($errorKey);
        expect(events10b($case))->toBe(0)
            ->and(row10b($case)->technical_track)->toBe('PENDING')
            ->and(row10b($case)->funding_track)->toBe('PENDING');
    } else {
        $response->assertSessionHasNoErrors();
        expect(events10b($case))->toBe(1);
    }
})->with([
    'not favourable, no reason' => ['cases.technical', ['outcome' => 'not_favourable'], 'reason'],
    'not favourable, short reason' => ['cases.technical', ['outcome' => 'not_favourable', 'reason' => 'too short'], 'reason'],
    'not favourable, blank reason' => ['cases.technical', ['outcome' => 'not_favourable', 'reason' => '          '], 'reason'],
    'not favourable, 501 chars' => ['cases.technical', ['outcome' => 'not_favourable', 'reason' => str_repeat('a', 501)], 'reason'],
    'not favourable, good reason' => ['cases.technical', ['outcome' => 'not_favourable', 'reason' => 'Product spec does not match.'], null],
    'favourable, no note' => ['cases.technical', ['outcome' => 'favourable'], null],
    'favourable, short note' => ['cases.technical', ['outcome' => 'favourable', 'reason' => 'OK'], null],
    'favourable, 501 chars' => ['cases.technical', ['outcome' => 'favourable', 'reason' => str_repeat('a', 501)], 'reason'],
    'not confirmed, no reason' => ['cases.funding', ['outcome' => 'not_confirmed'], 'reason'],
    'confirmed, no note' => ['cases.funding', ['outcome' => 'confirmed'], null],
    'no outcome' => ['cases.technical', [], 'outcome'],
    'funding outcome on the technical route' => ['cases.technical', ['outcome' => 'confirmed'], 'outcome'],
    'technical outcome on the funding route' => ['cases.funding', ['outcome' => 'favourable'], 'outcome'],
]);

it('lets a reviewer change their own outcome with a new row each time', function () {
    [$case, $tech] = reviewCase10b();

    $this->actingAs($tech)->post(route('cases.technical', $case), ['outcome' => 'not_favourable', 'reason' => 'Missing the certificate.'])->assertSessionHasNoErrors();
    expect(row10b($case)->technical_track)->toBe('NOT_FAVOURABLE');

    $this->actingAs($tech)->post(route('cases.technical', $case), ['outcome' => 'favourable'])->assertSessionHasNoErrors();

    $rows = DB::table('case_track_events')->where('case_id', $case->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('outcome')->all())->toBe(['not_favourable', 'favourable'])
        ->and($rows[0]->reason)->toBe('Missing the certificate.')
        ->and(row10b($case)->technical_track)->toBe('FAVOURABLE');
});

it('audits each outcome without the reason text', function () {
    [$case, $tech, $cab] = reviewCase10b();

    $this->actingAs($tech)->post(route('cases.technical', $case), ['outcome' => 'not_favourable', 'reason' => 'Secret reason text here.'])->assertSessionHasNoErrors();
    $this->actingAs($cab)->post(route('cases.funding', $case), ['outcome' => 'confirmed'])->assertSessionHasNoErrors();

    $technical = DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.technical_review_recorded')->get();
    $funding = DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.funding_recorded')->get();

    expect($technical)->toHaveCount(1)->and($funding)->toHaveCount(1)
        ->and($technical[0]->metadata)->not->toContain('Secret reason text');

    $meta = json_decode($technical[0]->metadata, true);
    expect($meta)->toMatchArray(['outcome' => 'not_favourable', 'previous_track' => 'PENDING', 'track' => 'NOT_FAVOURABLE', 'reason_given' => true])
        ->and(array_keys($meta))->toBe(['outcome', 'previous_outcome', 'previous_track', 'track', 'reason_given', 'reason_id', 'event_id'])
        ->and(json_decode($funding[0]->metadata, true))->toMatchArray(['outcome' => 'confirmed', 'track' => 'CONFIRMED', 'reason_given' => false]);
});

// 3. Recalculation table ---------------------------------------------------------

it('derives the track from each reviewer\'s latest outcome', function (string $desk, int $reviewers, array $steps, string $expected) {
    $desk = ReviewDesk::from($desk);
    $case = makeCase([
        'status' => CaseStatus::IN_REVIEW,
        'reference_no' => 'NR-10B-'.Str::upper(Str::random(8)),
        'technical_track' => TrackStatus::PENDING,
        'funding_track' => TrackStatus::PENDING,
    ]);
    $role = $desk === ReviewDesk::Technical ? Role::Technocrat : Role::CabOfficer;
    $users = [];
    foreach (range(0, $reviewers - 1) as $i) {
        $users[$i] = caseUser($role);
        reviewerRow10b($case, $desk, $users[$i]);
    }
    $action = $desk === ReviewDesk::Technical ? app(RecordTechnicalReview::class) : app(RecordFundingConfirmation::class);

    foreach ($steps as [$who, $outcome]) {
        $action->handle(fresh10b($case), $users[$who], $outcome, 'A reason long enough.');
    }

    expect(row10b($case)->{$desk->track()})->toBe($expected);
})->with([
    'one reviewer, favourable' => ['technical', 1, [[0, 'favourable']], 'FAVOURABLE'],
    'one reviewer, not favourable' => ['technical', 1, [[0, 'not_favourable']], 'NOT_FAVOURABLE'],
    'one reviewer, nothing yet' => ['technical', 1, [], 'PENDING'],
    'two, both favourable' => ['technical', 2, [[0, 'favourable'], [1, 'favourable']], 'FAVOURABLE'],
    'two, one not favourable' => ['technical', 2, [[0, 'favourable'], [1, 'not_favourable']], 'NOT_FAVOURABLE'],
    'two, one still pending' => ['technical', 2, [[0, 'favourable']], 'PENDING'],
    'two, a negative while the other is pending' => ['technical', 2, [[0, 'not_favourable']], 'NOT_FAVOURABLE'],
    'two, one flips negative to positive' => ['technical', 2, [[0, 'not_favourable'], [1, 'favourable'], [0, 'favourable']], 'FAVOURABLE'],
    'two, one flips positive to negative' => ['technical', 2, [[0, 'favourable'], [1, 'favourable'], [1, 'not_favourable']], 'NOT_FAVOURABLE'],
    'funding, two confirmed' => ['funding', 2, [[0, 'confirmed'], [1, 'confirmed']], 'CONFIRMED'],
    'funding, one not confirmed' => ['funding', 2, [[0, 'confirmed'], [1, 'not_confirmed']], 'NOT_CONFIRMED'],
    'funding, three, one pending' => ['funding', 3, [[0, 'confirmed'], [2, 'confirmed']], 'PENDING'],
]);

it('ignores the outcome of a reviewer who is no longer actively assigned', function () {
    [$case, $tech] = reviewCase10b();
    $second = caseUser(Role::Technocrat);
    $row = reviewerRow10b($case, ReviewDesk::Technical, $second);

    app(RecordTechnicalReview::class)->handle(fresh10b($case), $second, 'not_favourable', 'Second reviewer says no.');
    expect(row10b($case)->technical_track)->toBe('NOT_FAVOURABLE');

    $row->forceFill(['cancelled_at' => now()])->save();
    app(RecordTechnicalReview::class)->handle(fresh10b($case), $tech, 'favourable');

    expect(row10b($case)->technical_track)->toBe('FAVOURABLE');
});

// 4. Gate (T4) -------------------------------------------------------------------

it('passes the gate only when all three conditions hold', function (bool $technical, bool $funding, bool $hardcopy) {
    [$case] = reviewCase10b();
    setGate10b($case, $technical, $funding, $hardcopy);
    $cmo = caseUser(Role::Cmo);

    $props = pageProps($this->actingAs($cmo)->get(route('cases.show', $case))->assertOk());
    $response = $this->actingAs($cmo)->post(route('cases.ready', $case));

    $missing = array_values(array_filter([
        $technical ? null : 'Technical review is not favourable yet',
        $funding ? null : 'Funding is not confirmed yet',
        $hardcopy ? null : 'Hardcopy has not been received',
    ]));

    expect($props['gate']['open'])->toBe($missing === [])
        ->and($props['gate']['missing'])->toBe($missing)
        ->and(array_column($props['gate']['rows'], 'done'))->toBe([$technical, $funding, $hardcopy]);

    if ($missing === []) {
        $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Case marked ready for file.');
        $transitions = DB::table('case_transitions')->where('case_id', $case->id)->get();
        expect(row10b($case)->status)->toBe('READY_FOR_FILE')
            ->and(row10b($case)->current_holder_role)->toBe('cmo')
            ->and($transitions)->toHaveCount(1)
            ->and($transitions[0]->from_status)->toBe('IN_REVIEW')
            ->and($transitions[0]->to_status)->toBe('READY_FOR_FILE');
    } else {
        $response->assertRedirect()->assertSessionHasErrors(['gate' => 'Not ready for file: '.implode('; ', $missing).'.']);
        expect(row10b($case)->status)->toBe('IN_REVIEW')
            ->and(DB::table('case_transitions')->where('case_id', $case->id)->count())->toBe(0);
    }
})->with([true, false])->with([true, false])->with([true, false]);

it('keeps the gate closed on a negative track', function () {
    [$case] = reviewCase10b();
    setGate10b($case, true, true, true);
    DB::table('cases')->where('id', $case->id)->update(['funding_track' => 'NOT_CONFIRMED']);

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.ready', $case))
        ->assertSessionHasErrors(['gate' => 'Not ready for file: Funding is not confirmed yet.']);
    expect(row10b($case)->status)->toBe('IN_REVIEW');
});

it('closes recording and keeps hardcopy correction blocked after ready for file', function () {
    [$case, $tech, $cab] = reviewCase10b();
    $cmo = caseUser(Role::Cmo);
    setGate10b($case, true, true, true);

    $this->actingAs($cmo)->post(route('cases.ready', $case))->assertSessionHasNoErrors();
    $before = events10b($case);

    $this->actingAs($tech)->post(route('cases.technical', $case), ['outcome' => 'not_favourable', 'reason' => 'Changing my mind now.'])
        ->assertSessionHasErrors('review');
    $this->actingAs($cab)->post(route('cases.funding', $case), ['outcome' => 'not_confirmed', 'reason' => 'Changing my mind now.'])
        ->assertSessionHasErrors('review');
    $this->actingAs($cmo)->post(route('cases.hardcopy.reverse', $case), ['reason' => 'Trying to correct it now'])
        ->assertSessionHasErrors('hardcopy');

    $row = row10b($case);
    expect(events10b($case))->toBe($before)
        ->and($row->status)->toBe('READY_FOR_FILE')
        ->and($row->technical_track)->toBe('FAVOURABLE')
        ->and($row->hardcopy_received_at)->not->toBeNull();
});

// 5. Concurrency and fail closed -------------------------------------------------

it('refuses a stale record after the status moved on', function () {
    [$case, $tech] = reviewCase10b();
    $stale = fresh10b($case);
    setGate10b($case, true, true, true);
    app(MarkReadyForFile::class)->handle(fresh10b($case), caseUser(Role::Cmo));

    expect($stale->status)->toBe(CaseStatus::IN_REVIEW)
        ->and(fn () => app(RecordTechnicalReview::class)->handle($stale, $tech, 'not_favourable', 'Too late to change.'))
        ->toThrow(ValidationException::class);

    expect(events10b($case))->toBe(0)
        ->and(row10b($case)->technical_track)->toBe('FAVOURABLE');
});

it('rolls back the history row and the track when the audit write fails', function () {
    [$case, $tech] = reviewCase10b();

    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs') && in_array('case.technical_review_recorded', $query->bindings, true)) {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => app(RecordTechnicalReview::class)->handle($case, $tech, 'favourable'))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(events10b($case))->toBe(0)
        ->and(row10b($case)->technical_track)->toBe('PENDING')
        ->and(row10b($case)->status)->toBe('IN_REVIEW');
});

it('rolls back T4 when its audit write fails', function () {
    [$case] = reviewCase10b();
    setGate10b($case, true, true, true);

    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs') && in_array('case.transitioned', $query->bindings, true)) {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => app(MarkReadyForFile::class)->handle($case, caseUser(Role::Cmo)))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(row10b($case)->status)->toBe('IN_REVIEW')
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->count())->toBe(0);
});

// 6. Insert-only -----------------------------------------------------------------

it('refuses updates and deletes of track events', function () {
    [$case, $tech] = reviewCase10b();
    app(RecordTechnicalReview::class)->handle($case, $tech, 'favourable');
    $event = CaseTrackEvent::where('case_id', $case->id)->firstOrFail();

    expect(fn () => $event->forceFill(['outcome' => 'not_favourable'])->save())->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);

    // The database trigger refuses raw writes too.
    expect(fn () => DB::table('case_track_events')->where('id', $event->id)->update(['outcome' => 'not_favourable']))->toThrow(QueryException::class);
});

it('refuses raw deletes of track events at the database', function () {
    [$case, $tech] = reviewCase10b();
    app(RecordTechnicalReview::class)->handle($case, $tech, 'favourable');

    expect(fn () => DB::table('case_track_events')->where('case_id', $case->id)->delete())->toThrow(QueryException::class);
});

// 7. Props -----------------------------------------------------------------------

it('gives each reviewer only their own form, and the CMO the ready action', function () {
    [$case, $tech, $cab] = reviewCase10b();
    $cmo = caseUser(Role::Cmo);

    $techProps = pageProps($this->actingAs($tech)->get(route('cases.show', $case))->assertOk());
    $cabProps = pageProps($this->actingAs($cab)->get(route('cases.show', $case))->assertOk());
    $cmoProps = pageProps($this->actingAs($cmo)->get(route('cases.show', $case))->assertOk());

    expect($techProps['can'])->toMatchArray(['record_review' => true, 'mark_ready_for_file' => false])
        ->and(array_column($techProps['review_forms'], 'track'))->toBe(['technical'])
        ->and($techProps['review_forms'][0]['current'])->toBeNull()
        ->and(array_column($techProps['review_forms'][0]['outcomes'], 'value'))->toBe(['favourable', 'not_favourable'])
        ->and(array_column($cabProps['review_forms'], 'track'))->toBe(['funding'])
        ->and($cmoProps['can'])->toMatchArray(['record_review' => false, 'mark_ready_for_file' => true])
        ->and($cmoProps['review_forms'])->toBe([])
        ->and($cmoProps['gate']['open'])->toBeFalse();

    $this->actingAs($tech)->post(route('cases.technical', $case), ['outcome' => 'not_favourable', 'reason' => 'Certificate is missing.']);
    $techProps = pageProps($this->actingAs($tech)->get(route('cases.show', $case)));
    $cmoProps = pageProps($this->actingAs($cmo)->get(route('cases.show', $case)));

    expect($techProps['review_forms'][0]['current'])->toMatchArray(['outcome' => 'not_favourable', 'label' => 'Not favourable', 'reason' => 'Certificate is missing.'])
        ->and($cmoProps['gate']['rows'][0])->toMatchArray(['done' => false, 'state' => 'Not favourable'])
        ->and($cmoProps['gate']['rows'][0]['reviewers'][0])->toMatchArray([
            'name' => $tech->name, 'outcome' => 'Not favourable', 'reason' => 'Certificate is missing.',
        ]);
});

it('hides the forms once the case is ready for file', function () {
    [$case, $tech] = reviewCase10b();
    setGate10b($case, true, true, true);
    app(MarkReadyForFile::class)->handle($case, caseUser(Role::Cmo));

    $props = pageProps($this->actingAs($tech)->get(route('cases.show', $case))->assertOk());

    expect($props['review_forms'])->toBe([])
        ->and($props['can'])->toMatchArray(['record_review' => false, 'mark_ready_for_file' => false])
        ->and($props['gate']['open'])->toBeFalse()
        ->and($props['gate']['missing'])->toBe([]);
});

it('shows the vendor no outcomes, reasons or reviewer names', function () {
    [$case] = reviewCase10b();
    $tech = caseUser(Role::Technocrat, ['name' => 'Reviewer Name Must Not Leak']);
    reviewerRow10b($case, ReviewDesk::Technical, $tech);
    $vendor = vendorUser($case->organisation()->first());

    $this->actingAs($tech)->post(route('cases.technical', $case), ['outcome' => 'not_favourable', 'reason' => 'Reason must not leak to vendor'])
        ->assertSessionHasNoErrors();

    $props = pageProps($this->actingAs($vendor)->get(route('requests.show', $case))->assertOk());
    $scan = scanProps(collect($props)->except(['auth'])->all());

    expectVendorSafe($props);
    foreach (['gate', 'review_forms', 'outcome', 'reason', 'reviewers', 'missing'] as $key) {
        expect($scan['keys'])->not->toContain($key);
    }
    foreach ($scan['strings'] as $string) {
        expect($string)->not->toContain('Must Not Leak')
            ->and($string)->not->toContain('must not leak')
            ->and($string)->not->toContain('Not favourable')
            ->and($string)->not->toContain('NOT_FAVOURABLE');
    }
});

it('throttles the new routes at 20 a minute per user', function () {
    [$case, $tech] = reviewCase10b();

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($tech)->post(route('cases.technical', $case), [])->assertRedirect();
    }

    $this->actingAs($tech)->post(route('cases.funding', $case), [])->assertStatus(429);
});
