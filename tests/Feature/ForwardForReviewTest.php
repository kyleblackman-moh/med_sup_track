<?php

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\ReviewDesk;
use App\Enums\Role;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\ForwardForReview;
use App\Workflow\EligibleReviewers;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

// Slice 10a: send for review (T3, CMO_INTAKE -> IN_REVIEW). DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const ALL_ROLES_10A = ['cmo', 'cmo_registry', 'technocrat', 'cab_officer', 'legal_officer', 'ps_office', 'system_admin', 'auditor', 'vendor'];

/**
 * A case as IntakeCase leaves it: CMO_INTAKE, a completed registry pool row and
 * an active cmo_registry row for the accepting user. Returns [case, acceptor].
 *
 * @return array{0: ProcurementCase, 1: User}
 */
function intakeCase10a(array $attributes = []): array
{
    $case = makeCase([
        'status' => CaseStatus::CMO_INTAKE,
        'reference_no' => 'NR-10A-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(2),
        'current_holder_role' => 'cmo',
        ...$attributes,
    ]);
    $acceptor = caseUser(Role::CmoRegistry);
    assign($case, Role::CmoRegistry, completed: true);
    $row = assign($case, Role::CmoRegistry, $acceptor);
    $row->forceFill(['purpose' => 'intake'])->save();

    return [$case, $acceptor];
}

/** A user in $role who can at least see $case (assigned staff get an active row). */
function actorFor10a(string $role, ProcurementCase $case): User
{
    if ($role === 'vendor') {
        return vendorUser($case->organisation()->first());
    }

    $user = caseUser($role);
    if (in_array($role, ['cmo_registry', 'technocrat', 'cab_officer', 'legal_officer'], true)) {
        assign($case, $role, $user);
    }

    return $user;
}

function reviewer10a(Role $role, array $attributes = []): User
{
    return caseUser($role, ['name' => $role->label().' '.Str::upper(Str::random(6)), ...$attributes]);
}

/** @return array{technocrats: list<int>, cab_officers: list<int>, instruction?: string} */
function payload10a(array $technocrats, array $cab, ?string $instruction = null): array
{
    $ids = fn (array $users) => array_map(fn ($u) => $u instanceof User ? $u->id : $u, $users);

    return array_filter([
        'technocrats' => $ids($technocrats),
        'cab_officers' => $ids($cab),
        'instruction' => $instruction,
    ], fn ($v) => $v !== null);
}

function caseRow10a(ProcurementCase $case): object
{
    return DB::table('cases')->where('id', $case->id)->first();
}

function auditCount10a(ProcurementCase $case, string $action): int
{
    return DB::table('audit_logs')->where('case_id', $case->id)->where('action', $action)->count();
}

/** Row counts that a refused forward must leave unchanged. */
function snapshot10a(ProcurementCase $case): array
{
    $row = caseRow10a($case);

    return [
        'status' => $row->status,
        'technical_track' => $row->technical_track,
        'funding_track' => $row->funding_track,
        'transitions' => DB::table('case_transitions')->where('case_id', $case->id)->count(),
        'assignments' => DB::table('case_assignments')->where('case_id', $case->id)->count(),
        'audit' => DB::table('audit_logs')->where('case_id', $case->id)->count(),
    ];
}

function forward10a(User $actor, ProcurementCase $case, array $payload): TestResponse
{
    return test()->actingAs($actor)->post(route('cases.forward', $case), $payload);
}

// 1. Policy matrix --------------------------------------------------------------

function expectedForward(string $role, CaseStatus $status): string
{
    return match (true) {
        $role === 'vendor' => 'forbidden',                                  // route middleware
        in_array($role, ['system_admin', 'auditor'], true) => 'notfound', // never see a case
        $status === CaseStatus::DRAFT => 'notfound',                         // staff never see drafts
        $role !== 'cmo' => 'forbidden',                                      // no case.route_to_review
        $status->isTerminal() => 'forbidden',                                // act() refuses terminal cases
        $status === CaseStatus::CMO_INTAKE => 'ok',
        default => 'error',                                                  // wrong status, never sent
    };
}

it('allows send for review exactly as the policy matrix says', function (string $role, CaseStatus $status) {
    [$case] = intakeCase10a(['status' => $status]);
    $actor = actorFor10a($role, $case);
    $expected = expectedForward($role, $status);

    $response = forward10a($actor, $case, payload10a([reviewer10a(Role::Technocrat)], [reviewer10a(Role::CabOfficer)]));

    match ($expected) {
        'ok' => $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success'),
        'error' => $response->assertRedirect()->assertSessionHasErrors('forward'),
        'forbidden' => $response->assertForbidden(),
        'notfound' => $response->assertNotFound(),
    };

    expect(caseRow10a($case)->status)->toBe($expected === 'ok' ? 'IN_REVIEW' : $status->value);
})->with(ALL_ROLES_10A)->with([
    CaseStatus::DRAFT, CaseStatus::SUBMITTED, CaseStatus::CMO_INTAKE, CaseStatus::IN_REVIEW,
    CaseStatus::READY_FOR_FILE, CaseStatus::WITH_PS, CaseStatus::CLOSED, CaseStatus::WITHDRAWN,
]);

// 2. Effects ---------------------------------------------------------------------

it('moves the case to IN_REVIEW, sets both tracks pending and assigns each reviewer', function () {
    [$case, $acceptor] = intakeCase10a();
    $cmo = caseUser(Role::Cmo);
    $tech = reviewer10a(Role::Technocrat);
    $cab = reviewer10a(Role::CabOfficer);
    $acceptorRowBefore = DB::table('case_assignments')->where('case_id', $case->id)->where('user_id', $acceptor->id)->first();

    forward10a($cmo, $case, payload10a([$tech], [$cab], '  Please check the stock figures.  '))
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Case sent for review.');

    $row = caseRow10a($case);
    $transitions = DB::table('case_transitions')->where('case_id', $case->id)->get();
    $reviewRows = DB::table('case_assignments')->where('case_id', $case->id)->whereIn('purpose', ['technical', 'funding'])->orderBy('id')->get();
    $acceptorRowAfter = DB::table('case_assignments')->where('id', $acceptorRowBefore->id)->first();

    expect($row->status)->toBe('IN_REVIEW')
        ->and($row->current_holder_role)->toBeNull()
        ->and($row->technical_track)->toBe('PENDING')
        ->and($row->funding_track)->toBe('PENDING')
        ->and($transitions)->toHaveCount(1)
        ->and($transitions->first()->from_status)->toBe('CMO_INTAKE')
        ->and($transitions->first()->to_status)->toBe('IN_REVIEW')
        ->and($transitions->first()->reason)->toBe('Please check the stock figures.')
        ->and((int) $transitions->first()->actor_id)->toBe($cmo->id)
        ->and($reviewRows)->toHaveCount(2)
        ->and((int) $reviewRows[0]->user_id)->toBe($tech->id)
        ->and($reviewRows[0]->role)->toBe('technocrat')
        ->and($reviewRows[0]->purpose)->toBe('technical')
        ->and((int) $reviewRows[0]->assigned_by)->toBe($cmo->id)
        ->and($reviewRows[0]->completed_at)->toBeNull()
        ->and((int) $reviewRows[1]->user_id)->toBe($cab->id)
        ->and($reviewRows[1]->role)->toBe('cab_officer')
        ->and($reviewRows[1]->purpose)->toBe('funding')
        ->and($acceptorRowAfter)->toEqual($acceptorRowBefore)
        ->and(auditCount10a($case, 'case.transitioned'))->toBe(1)
        ->and(auditCount10a($case, 'case.assignment_created'))->toBe(2)
        ->and(auditCount10a($case, 'case.forwarded_for_review'))->toBe(1);

    $metadata = json_decode(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.forwarded_for_review')->value('metadata'), true);
    expect($metadata)->toBe(['technocrats' => 1, 'cab_officers' => 1, 'instruction_given' => true]);
});

it('stores no reason when no instruction is given', function () {
    [$case] = intakeCase10a();

    forward10a(caseUser(Role::Cmo), $case, payload10a([reviewer10a(Role::Technocrat)], [reviewer10a(Role::CabOfficer)], '   '))
        ->assertSessionHasNoErrors();

    expect(DB::table('case_transitions')->where('case_id', $case->id)->value('reason'))->toBeNull();
});

it('does not require the hardcopy', function () {
    [$case] = intakeCase10a();
    expect(caseRow10a($case)->hardcopy_received_at)->toBeNull();

    forward10a(caseUser(Role::Cmo), $case, payload10a([reviewer10a(Role::Technocrat)], [reviewer10a(Role::CabOfficer)]))
        ->assertSessionHasNoErrors();

    expect(caseRow10a($case)->status)->toBe('IN_REVIEW');
});

it('lets the chosen reviewers see the case and nobody else new', function () {
    [$case, $acceptor] = intakeCase10a();
    $tech = reviewer10a(Role::Technocrat);
    $cab = reviewer10a(Role::CabOfficer);
    $otherTech = reviewer10a(Role::Technocrat);
    $otherCab = reviewer10a(Role::CabOfficer);
    $otherRegistry = caseUser(Role::CmoRegistry);

    $this->actingAs($tech)->get(route('cases.show', $case))->assertNotFound();

    forward10a(caseUser(Role::Cmo), $case, payload10a([$tech], [$cab]))->assertSessionHasNoErrors();

    $this->actingAs($tech)->get(route('cases.show', $case))->assertOk();
    $this->actingAs($cab)->get(route('cases.show', $case))->assertOk();
    $this->actingAs($acceptor)->get(route('cases.show', $case))->assertOk();
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk();
    $this->actingAs(caseUser(Role::PsOffice))->get(route('cases.show', $case))->assertOk();
    $this->actingAs($otherTech)->get(route('cases.show', $case))->assertNotFound();
    $this->actingAs($otherCab)->get(route('cases.show', $case))->assertNotFound();
    $this->actingAs($otherRegistry)->get(route('cases.show', $case))->assertNotFound();

    $listed = fn (User $u) => collect(pageProps($this->actingAs($u)->get(route('cases.index')))['cases']['data'])->pluck('id')->all();
    expect($listed($tech))->toContain($case->id)
        ->and($listed($otherTech))->not->toContain($case->id);
});

// 3. Validation ------------------------------------------------------------------

it('refuses invalid reviewer choices with field errors and no state change', function (Closure $build, string $errorKey) {
    [$case] = intakeCase10a();
    $before = snapshot10a($case);

    forward10a(caseUser(Role::Cmo), $case, $build())
        ->assertRedirect()->assertSessionHasErrors($errorKey);

    expect(snapshot10a($case))->toBe($before);
})->with([
    'no technocrats' => [fn () => payload10a([], [reviewer10a(Role::CabOfficer)]), 'technocrats'],
    'no CAB officers' => [fn () => payload10a([reviewer10a(Role::Technocrat)], []), 'cab_officers'],
    'technocrats missing' => [fn () => ['cab_officers' => [reviewer10a(Role::CabOfficer)->id]], 'technocrats'],
    'four technocrats' => [fn () => payload10a(
        [reviewer10a(Role::Technocrat), reviewer10a(Role::Technocrat), reviewer10a(Role::Technocrat), reviewer10a(Role::Technocrat)],
        [reviewer10a(Role::CabOfficer)],
    ), 'technocrats'],
    'four CAB officers' => [fn () => payload10a(
        [reviewer10a(Role::Technocrat)],
        [reviewer10a(Role::CabOfficer), reviewer10a(Role::CabOfficer), reviewer10a(Role::CabOfficer), reviewer10a(Role::CabOfficer)],
    ), 'cab_officers'],
    'unknown technocrat id' => [fn () => payload10a([999999999], [reviewer10a(Role::CabOfficer)]), 'technocrats.0'],
    'unknown CAB id' => [fn () => payload10a([reviewer10a(Role::Technocrat)], [999999999]), 'cab_officers.0'],
    'non-numeric id' => [fn () => payload10a(['abc'], [reviewer10a(Role::CabOfficer)]), 'technocrats.0'],
    'inactive technocrat' => [fn () => payload10a([reviewer10a(Role::Technocrat, ['status' => User::STATUS_DISABLED])], [reviewer10a(Role::CabOfficer)]), 'technocrats'],
    'inactive CAB officer' => [fn () => payload10a([reviewer10a(Role::Technocrat)], [reviewer10a(Role::CabOfficer, ['status' => User::STATUS_DISABLED])]), 'cab_officers'],
    'vendor as technocrat' => [fn () => payload10a([vendorUser(vendorOrg())], [reviewer10a(Role::CabOfficer)]), 'technocrats'],
    'vendor holding the permission' => [function () {
        $vendor = vendorUser(vendorOrg());
        $vendor->givePermissionTo(Permission::TechnicalConsider->value);

        return payload10a([$vendor], [reviewer10a(Role::CabOfficer)]);
    }, 'technocrats'],
    'admin as technocrat' => [fn () => payload10a([caseUser(Role::SystemAdmin)], [reviewer10a(Role::CabOfficer)]), 'technocrats'],
    'cmo as CAB officer' => [fn () => payload10a([reviewer10a(Role::Technocrat)], [caseUser(Role::Cmo)]), 'cab_officers'],
    'CAB officer at the technical desk' => [fn () => payload10a([reviewer10a(Role::CabOfficer)], [reviewer10a(Role::CabOfficer)]), 'technocrats'],
    'technocrat at the funding desk' => [fn () => payload10a([reviewer10a(Role::Technocrat)], [reviewer10a(Role::Technocrat)]), 'cab_officers'],
    'one eligible, one not' => [fn () => payload10a([reviewer10a(Role::Technocrat), reviewer10a(Role::LegalOfficer)], [reviewer10a(Role::CabOfficer)]), 'technocrats'],
    'instruction too long' => [fn () => payload10a([reviewer10a(Role::Technocrat)], [reviewer10a(Role::CabOfficer)], str_repeat('a', 501)), 'instruction'],
]);

it('collapses duplicate ids into one assignment each', function () {
    [$case] = intakeCase10a();
    $tech = reviewer10a(Role::Technocrat);
    $cab = reviewer10a(Role::CabOfficer);

    forward10a(caseUser(Role::Cmo), $case, [
        'technocrats' => [$tech->id, $tech->id, (string) $tech->id],
        'cab_officers' => [$cab->id, $cab->id, $cab->id, $cab->id],
    ])->assertSessionHasNoErrors();

    $rows = DB::table('case_assignments')->where('case_id', $case->id)->whereIn('purpose', ['technical', 'funding'])->get();
    expect($rows)->toHaveCount(2)
        ->and(auditCount10a($case, 'case.assignment_created'))->toBe(2);
});

it('collapses duplicates in the service too', function () {
    [$case] = intakeCase10a();
    $tech = reviewer10a(Role::Technocrat);
    $cab = reviewer10a(Role::CabOfficer);

    app(ForwardForReview::class)->handle($case, caseUser(Role::Cmo), [$tech->id, $tech->id], [$cab->id, (string) $cab->id]);

    expect(DB::table('case_assignments')->where('case_id', $case->id)->whereIn('purpose', ['technical', 'funding'])->count())->toBe(2);
});

// 4. Concurrency -----------------------------------------------------------------

it('returns AlreadyForwarded on a second send, naming the first user, with no extra rows', function () {
    [$case] = intakeCase10a();
    $first = caseUser(Role::Cmo, ['name' => 'First Sender']);
    $second = caseUser(Role::Cmo);

    forward10a($first, $case, payload10a([reviewer10a(Role::Technocrat)], [reviewer10a(Role::CabOfficer)]))->assertSessionHasNoErrors();
    $after = snapshot10a($case);

    forward10a($second, $case, payload10a([reviewer10a(Role::Technocrat)], [reviewer10a(Role::CabOfficer)]))
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('info', fn (string $m) => str_contains($m, 'already sent for review by First Sender'));

    expect(snapshot10a($case))->toBe($after);
});

it('treats a stale case instance the same way', function () {
    [$case] = intakeCase10a();
    $stale = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
    $first = caseUser(Role::Cmo, ['name' => 'First Sender']);

    app(ForwardForReview::class)->handle($case, $first, [reviewer10a(Role::Technocrat)->id], [reviewer10a(Role::CabOfficer)->id]);
    $after = snapshot10a($case);

    expect($stale->status)->toBe(CaseStatus::CMO_INTAKE);
    $result = app(ForwardForReview::class)->handle($stale, caseUser(Role::Cmo), [reviewer10a(Role::Technocrat)->id], [reviewer10a(Role::CabOfficer)->id]);

    expect($result->alreadyForwarded)->toBeTrue()
        ->and($result->forwardedBy)->toBe('First Sender')
        ->and($result->forwardedAt)->not->toBeNull()
        ->and(snapshot10a($case))->toBe($after);
});

// 5. Fail closed -----------------------------------------------------------------

it('rolls back status, tracks and assignments when an audit write fails', function () {
    [$case] = intakeCase10a();
    $before = snapshot10a($case);

    // Fail the LAST audit write, so everything before it must roll back too.
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs') && in_array('case.forwarded_for_review', $query->bindings, true)) {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => app(ForwardForReview::class)->handle(
        $case, caseUser(Role::Cmo), [reviewer10a(Role::Technocrat)->id], [reviewer10a(Role::CabOfficer)->id],
    ))->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(snapshot10a($case))->toBe($before)
        ->and($before['technical_track'])->toBeNull()
        ->and($before['status'])->toBe('CMO_INTAKE');
});

// 6. Lookup ----------------------------------------------------------------------

it('lists only active eligible staff, with id and name only', function () {
    $tech = reviewer10a(Role::Technocrat);
    $cab = reviewer10a(Role::CabOfficer);
    $disabled = reviewer10a(Role::Technocrat, ['status' => User::STATUS_DISABLED]);
    $admin = caseUser(Role::SystemAdmin);
    $cmo = caseUser(Role::Cmo);
    $vendor = vendorUser(vendorOrg());
    $vendor->givePermissionTo(Permission::TechnicalConsider->value);
    $direct = caseUser(null);
    $direct->givePermissionTo(Permission::FundingConfirm->value);

    $lookup = app(EligibleReviewers::class);
    $technical = $lookup->for(ReviewDesk::Technical);
    $funding = $lookup->for(ReviewDesk::Funding);
    $ids = fn (array $rows) => array_column($rows, 'id');

    expect($ids($technical))->toContain($tech->id)
        ->not->toContain($disabled->id)
        ->not->toContain($cab->id)
        ->not->toContain($admin->id)
        ->not->toContain($cmo->id)
        ->not->toContain($vendor->id)
        ->and($ids($funding))->toContain($cab->id)->toContain($direct->id)->not->toContain($tech->id);

    foreach ([...$technical, ...$funding] as $row) {
        expect(array_keys($row))->toBe(['id', 'name'])
            ->and($row['id'])->toBeInt()
            ->and($row['name'])->toBeString();
    }

    $names = array_column($technical, 'name');
    $sorted = $names;
    sort($sorted, SORT_STRING | SORT_FLAG_CASE);
    expect(array_map('mb_strtolower', $names))->toBe(array_map('mb_strtolower', $sorted));
});

// 7. Props -----------------------------------------------------------------------

it('gives the reviewer lists and the can flag only to a user who may send the case', function () {
    [$case, $acceptor] = intakeCase10a();
    $tech = reviewer10a(Role::Technocrat);

    $cmoProps = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk());
    $registryProps = pageProps($this->actingAs($acceptor)->get(route('cases.show', $case))->assertOk());
    $psProps = pageProps($this->actingAs(caseUser(Role::PsOffice))->get(route('cases.show', $case))->assertOk());

    expect($cmoProps['can']['forward_for_review'])->toBeTrue()
        ->and(array_keys($cmoProps['reviewers']))->toBe(['technocrats', 'cab_officers'])
        ->and(array_column($cmoProps['reviewers']['technocrats'], 'id'))->toContain($tech->id)
        ->and($cmoProps['gate'])->toBeNull()
        ->and($registryProps['can']['forward_for_review'])->toBeFalse()
        ->and($registryProps['reviewers'])->toBeNull()
        ->and($psProps['can']['forward_for_review'])->toBeFalse()
        ->and($psProps['reviewers'])->toBeNull();

    foreach ([...$cmoProps['reviewers']['technocrats'], ...$cmoProps['reviewers']['cab_officers']] as $row) {
        expect(array_keys($row))->toBe(['id', 'name']);
    }
});

it('shows review progress after the case is sent', function () {
    [$case] = intakeCase10a();
    $cmo = caseUser(Role::Cmo);
    $tech = reviewer10a(Role::Technocrat);
    $cab = reviewer10a(Role::CabOfficer);

    forward10a($cmo, $case, payload10a([$tech], [$cab]))->assertSessionHasNoErrors();

    $props = pageProps($this->actingAs($tech)->get(route('cases.show', $case))->assertOk());

    // Slice 10b turned review_progress into the gate tracker (gate.rows).
    $pending = fn (User $u) => ['name' => $u->name, 'outcome' => null, 'recorded_at' => null, 'reason' => null];
    $none = ['received_on' => null, 'recorded_by' => null, 'recorded_at' => null];

    expect($props['can']['forward_for_review'])->toBeFalse()
        ->and($props['reviewers'])->toBeNull()
        ->and($props['gate']['rows'])->toBe([
            ['key' => 'technical', 'label' => 'Technical Review', 'done' => false, 'state' => 'Pending', 'reviewers' => [$pending($tech)], 'history' => [], ...$none],
            ['key' => 'funding', 'label' => 'Funding Confirmation', 'done' => false, 'state' => 'Pending', 'reviewers' => [$pending($cab)], 'history' => [], ...$none],
            ['key' => 'hardcopy', 'label' => 'Hardcopy Received', 'done' => false, 'state' => 'Not received', 'reviewers' => [], 'history' => [], ...$none],
        ]);

    $this->actingAs($cmo)->post(route('cases.hardcopy.store', $case), ['received_on' => CarbonImmutable::now('America/Port_of_Spain')->toDateString()])
        ->assertSessionHasNoErrors();
    $props = pageProps($this->actingAs($cmo)->get(route('cases.show', $case))->assertOk());

    expect($props['gate']['rows'][2])->toMatchArray([
        'key' => 'hardcopy', 'label' => 'Hardcopy Received', 'done' => true, 'state' => 'Received', 'reviewers' => [],
        'received_on' => CarbonImmutable::now('America/Port_of_Spain')->toDateString(),
        'recorded_by' => $cmo->name,
    ]);
});

it('shows the vendor no reviewer names and no instruction', function () {
    [$case] = intakeCase10a();
    $vendor = vendorUser($case->organisation()->first());
    $tech = reviewer10a(Role::Technocrat, ['name' => 'Reviewer Name Must Not Leak']);
    $cab = reviewer10a(Role::CabOfficer, ['name' => 'Budget Name Must Not Leak']);

    forward10a(caseUser(Role::Cmo), $case, payload10a([$tech], [$cab], 'Instruction must not leak'))->assertSessionHasNoErrors();

    $props = pageProps($this->actingAs($vendor)->get(route('requests.show', $case))->assertOk());
    $scan = scanProps(collect($props)->except(['auth'])->all());

    expectVendorSafe($props);
    foreach (['reviewers', 'review_progress', 'gate', 'review_forms', 'assignments', 'timeline', 'reason', 'instruction'] as $key) {
        expect($scan['keys'])->not->toContain($key);
    }
    foreach ($scan['strings'] as $string) {
        expect($string)->not->toContain('Must Not Leak')
            ->and($string)->not->toContain('Instruction must not leak');
    }
});

// 8. Route -----------------------------------------------------------------------

it('throttles the route at 20 a minute per user', function () {
    [$case] = intakeCase10a();
    $cmo = caseUser(Role::Cmo);

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($cmo)->post(route('cases.forward', $case), [])->assertRedirect();
    }

    $this->actingAs($cmo)->post(route('cases.forward', $case), [])->assertStatus(429);
});
