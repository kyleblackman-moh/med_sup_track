<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\IntakeCase;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

// Slice 9b: CMO intake (T2) and hardcopy received. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const ALL_ROLES_9B = ['cmo', 'cmo_registry', 'technocrat', 'cab_officer', 'legal_officer', 'ps_office', 'system_admin', 'auditor', 'vendor'];

/**
 * A case as SubmitCase leaves it: SUBMITTED, a reference, submitted two days
 * ago (Port of Spain), and an active cmo_registry pool row.
 */
function submittedCase9b(array $attributes = []): ProcurementCase
{
    $case = makeCase([
        'status' => CaseStatus::SUBMITTED,
        'reference_no' => 'NR-9B-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('America/Port_of_Spain')->subDays(2)->setTime(10, 0)->utc(),
        'current_holder_role' => 'cmo_registry',
        ...$attributes,
    ]);
    assign($case, Role::CmoRegistry);

    return $case;
}

/** A user in $role who can at least see $case (assigned staff get an active row). */
function actorFor9b(string $role, ProcurementCase $case): User
{
    if ($role === 'vendor') {
        return vendorUser($case->organisation()->first());
    }

    $user = caseUser($role);
    // system_admin and auditor never hold assignments, so they see no case at all.
    if (in_array($role, ['technocrat', 'cab_officer', 'legal_officer'], true)) {
        assign($case, $role, $user);
    }

    return $user;
}

function caseRow9b(ProcurementCase $case): object
{
    return DB::table('cases')->where('id', $case->id)->first();
}

function auditCount9b(ProcurementCase $case, string $action): int
{
    return DB::table('audit_logs')->where('case_id', $case->id)->where('action', $action)->count();
}

function poSToday(): string
{
    return CarbonImmutable::now('America/Port_of_Spain')->toDateString();
}

/** Asserts the response refused the request in one of the expected ways. */
function expectOutcome(TestResponse $response, string $expected, string $errorKey): void
{
    match ($expected) {
        'ok' => $response->assertRedirect()->assertSessionHasNoErrors(),
        'error' => $response->assertRedirect()->assertSessionHasErrors($errorKey),
        'forbidden' => $response->assertForbidden(),
        'notfound' => $response->assertNotFound(),
    };
}

// 1. Intake policy matrix --------------------------------------------------------

function expectedIntake(string $role, CaseStatus $status): string
{
    return match (true) {
        $role === 'vendor' => 'forbidden',                                  // route middleware
        in_array($role, ['system_admin', 'auditor'], true) => 'notfound', // never see a case
        $status === CaseStatus::DRAFT => 'notfound',                         // staff never see drafts
        ! in_array($role, ['cmo', 'cmo_registry'], true) => 'forbidden',    // no case.intake
        $status->isTerminal() => 'forbidden',                                // act() refuses terminal cases
        $status === CaseStatus::SUBMITTED => 'ok',
        default => 'error',                                                  // wrong status, never accepted
    };
}

it('allows intake exactly as the policy matrix says', function (string $role, CaseStatus $status) {
    $case = submittedCase9b(['status' => $status]);
    $actor = actorFor9b($role, $case);
    $expected = expectedIntake($role, $status);

    expectOutcome($this->actingAs($actor)->post(route('cases.intake', $case)), $expected, 'intake');

    expect(caseRow9b($case)->status)->toBe($expected === 'ok' ? 'CMO_INTAKE' : $status->value);
})->with(ALL_ROLES_9B)->with([
    CaseStatus::DRAFT, CaseStatus::SUBMITTED, CaseStatus::CMO_INTAKE, CaseStatus::IN_REVIEW,
    CaseStatus::WITH_PS, CaseStatus::CLOSED, CaseStatus::WITHDRAWN,
]);

// 2. Intake effects --------------------------------------------------------------

it('moves the case to CMO_INTAKE, completes the pool row and assigns the accepting user', function () {
    $case = submittedCase9b();
    $registry = caseUser(Role::CmoRegistry);

    $this->actingAs($registry)->post(route('cases.intake', $case))
        ->assertRedirect()->assertSessionHas('success');

    $row = caseRow9b($case);
    $assignments = DB::table('case_assignments')->where('case_id', $case->id)->orderBy('id')->get();
    $pool = $assignments->firstWhere('user_id', null);
    $active = $assignments->whereNull('completed_at')->whereNull('cancelled_at');

    expect($row->status)->toBe('CMO_INTAKE')
        ->and($row->current_holder_role)->toBe('cmo')
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->where('to_status', 'CMO_INTAKE')->count())->toBe(1)
        ->and($assignments)->toHaveCount(2)
        ->and($pool->completed_at)->not->toBeNull()
        ->and($active)->toHaveCount(1)
        ->and((int) $active->first()->user_id)->toBe($registry->id)
        ->and($active->first()->role)->toBe('cmo_registry')
        ->and(auditCount9b($case, 'case.transitioned'))->toBe(1)
        ->and(auditCount9b($case, 'case.assignment_completed'))->toBe(1)
        ->and(auditCount9b($case, 'case.assignment_created'))->toBe(1);
});

it('gives a CMO who accepts the registry desk row, not a cmo row', function () {
    $case = submittedCase9b();
    $cmo = caseUser(Role::Cmo);

    $this->actingAs($cmo)->post(route('cases.intake', $case))->assertSessionHasNoErrors();

    $active = DB::table('case_assignments')->where('case_id', $case->id)->whereNull('completed_at')->get();
    expect($active)->toHaveCount(1)
        ->and((int) $active->first()->user_id)->toBe($cmo->id)
        ->and($active->first()->role)->toBe('cmo_registry');
});

it('hides the case from other registry users after intake; the acceptor, cmo and ps_office still see it', function () {
    $case = submittedCase9b();
    $acceptor = caseUser(Role::CmoRegistry);
    $colleague = caseUser(Role::CmoRegistry);

    $this->actingAs($colleague)->get(route('cases.show', $case))->assertOk(); // before: pool row

    $this->actingAs($acceptor)->post(route('cases.intake', $case))->assertSessionHasNoErrors();

    $this->actingAs($acceptor)->get(route('cases.show', $case))->assertOk();
    $this->actingAs($colleague)->get(route('cases.show', $case))->assertNotFound();
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk();
    $this->actingAs(caseUser(Role::PsOffice))->get(route('cases.show', $case))->assertOk();
});

it('answers a second accept with who accepted it, and writes nothing more', function () {
    $case = submittedCase9b();
    $first = caseUser(Role::Cmo, ['name' => 'First Acceptor']);
    $second = caseUser(Role::Cmo);

    $this->actingAs($first)->post(route('cases.intake', $case))->assertSessionHasNoErrors();
    $counts = fn () => [
        DB::table('case_transitions')->where('case_id', $case->id)->count(),
        DB::table('case_assignments')->where('case_id', $case->id)->count(),
        DB::table('audit_logs')->where('case_id', $case->id)->count(),
    ];
    $before = $counts();

    $this->actingAs($second)->post(route('cases.intake', $case))
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('info', fn (string $message) => str_contains($message, 'First Acceptor'));

    expect($counts())->toBe($before);
});

it('rolls back the transition, pool row and assignment when an audit write fails', function () {
    $case = submittedCase9b();
    $registry = caseUser(Role::CmoRegistry);

    // Fail the LAST audit write, so everything before it must roll back too.
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs') && in_array('case.assignment_created', $query->bindings, true)) {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => app(IntakeCase::class)->handle($case, $registry))->toThrow(RuntimeException::class, 'audit store unavailable');

    $assignments = DB::table('case_assignments')->where('case_id', $case->id)->get();
    expect(caseRow9b($case)->status)->toBe('SUBMITTED')
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->count())->toBe(0)
        ->and($assignments)->toHaveCount(1)
        ->and($assignments->first()->completed_at)->toBeNull()
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->count())->toBe(0);
});

// 3. Hardcopy policy matrix ------------------------------------------------------

function expectedHardcopy(string $role, CaseStatus $status): string
{
    return match (true) {
        $role === 'vendor' => 'forbidden',
        in_array($role, ['system_admin', 'auditor'], true) => 'notfound',
        $status === CaseStatus::DRAFT => 'notfound',
        ! in_array($role, ['cmo', 'cmo_registry'], true) => 'forbidden', // no hardcopy.confirm
        $status->isTerminal() => 'forbidden',
        in_array($status, [CaseStatus::SUBMITTED, CaseStatus::CMO_INTAKE, CaseStatus::IN_REVIEW], true) => 'ok',
        default => 'error', // READY_FOR_FILE and later
    };
}

const HARDCOPY_STATUSES = [
    CaseStatus::DRAFT, CaseStatus::SUBMITTED, CaseStatus::CMO_INTAKE, CaseStatus::IN_REVIEW,
    CaseStatus::READY_FOR_FILE, CaseStatus::FILE_PREPARED, CaseStatus::WITH_LEGAL, CaseStatus::CLOSED,
];

it('allows marking the hardcopy exactly as the policy matrix says', function (string $role, CaseStatus $status) {
    $case = submittedCase9b(['status' => $status]);
    $actor = actorFor9b($role, $case);
    $expected = expectedHardcopy($role, $status);

    expectOutcome($this->actingAs($actor)->post(route('cases.hardcopy.store', $case), ['received_on' => poSToday()]), $expected, 'hardcopy');

    $row = caseRow9b($case);
    if ($expected !== 'ok') {
        expect($row->hardcopy_received_on)->toBeNull();
    } else {
        expect(substr((string) $row->hardcopy_received_on, 0, 10))->toBe(poSToday())
            ->and((int) $row->hardcopy_received_by)->toBe($actor->id)
            ->and($row->hardcopy_received_at)->not->toBeNull()
            ->and(auditCount9b($case, 'case.hardcopy_received'))->toBe(1);
    }
})->with(ALL_ROLES_9B)->with(HARDCOPY_STATUSES);

it('allows reversing the hardcopy exactly as the policy matrix says', function (string $role, CaseStatus $status) {
    $case = submittedCase9b(['status' => $status, 'hardcopy_received_on' => poSToday(), 'hardcopy_received_at' => now(), 'hardcopy_received_by' => caseUser(Role::Cmo)->id]);
    $actor = actorFor9b($role, $case);
    $expected = expectedHardcopy($role, $status);

    expectOutcome($this->actingAs($actor)->post(route('cases.hardcopy.reverse', $case), ['reason' => 'Recorded on the wrong case']), $expected, 'hardcopy');

    $row = caseRow9b($case);
    if ($expected === 'ok') {
        expect($row->hardcopy_received_on)->toBeNull()
            ->and($row->hardcopy_received_at)->toBeNull()
            ->and($row->hardcopy_received_by)->toBeNull();
    } else {
        expect($row->hardcopy_received_on)->not->toBeNull();
    }
})->with(ALL_ROLES_9B)->with(HARDCOPY_STATUSES);

// 4. Hardcopy rules -------------------------------------------------------------

it('validates the received date', function (string $date, bool $ok) {
    $case = submittedCase9b();
    $submitted = CarbonImmutable::parse($case->submitted_at)->setTimezone('America/Port_of_Spain');
    $value = match ($date) {
        'today' => poSToday(),
        'submission day' => $submitted->toDateString(),
        'tomorrow' => CarbonImmutable::now('America/Port_of_Spain')->addDay()->toDateString(),
        'day before submission' => $submitted->subDay()->toDateString(),
        default => $date,
    };

    $response = $this->actingAs(caseUser(Role::Cmo))->post(route('cases.hardcopy.store', $case), ['received_on' => $value]);

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('received_on');
    expect(caseRow9b($case)->hardcopy_received_on === null)->toBe(! $ok);
})->with([
    ['today', true],
    ['submission day', true],
    ['tomorrow', false],
    ['day before submission', false],
    ['2026-02-30', false],
    ['13/11/2026', false],
    ['', false],
]);

it('leaves the first record alone when the hardcopy is marked twice', function () {
    $case = submittedCase9b();
    $first = caseUser(Role::CmoRegistry);
    $yesterday = CarbonImmutable::now('America/Port_of_Spain')->subDay()->toDateString();

    $this->actingAs($first)->post(route('cases.hardcopy.store', $case), ['received_on' => $yesterday])->assertSessionHasNoErrors();
    $before = caseRow9b($case);

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.hardcopy.store', $case), ['received_on' => poSToday()])
        ->assertSessionHasErrors('received_on');

    $after = caseRow9b($case);
    expect($after->hardcopy_received_on)->toBe($before->hardcopy_received_on)
        ->and((int) $after->hardcopy_received_by)->toBe($first->id)
        ->and(auditCount9b($case, 'case.hardcopy_received'))->toBe(1);
});

it('requires a reason of 10 to 500 characters to reverse', function (?string $reason) {
    $case = submittedCase9b(['hardcopy_received_on' => poSToday(), 'hardcopy_received_at' => now(), 'hardcopy_received_by' => caseUser(Role::Cmo)->id]);

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.hardcopy.reverse', $case), ['reason' => $reason])
        ->assertSessionHasErrors('reason');

    expect(caseRow9b($case)->hardcopy_received_on)->not->toBeNull();
})->with([null, '', 'too short', str_repeat('x', 501), '          padded   ']);

it('clears the record and audits the reason and previous date', function () {
    $recorder = caseUser(Role::CmoRegistry);
    $case = submittedCase9b(['hardcopy_received_on' => poSToday(), 'hardcopy_received_at' => now(), 'hardcopy_received_by' => $recorder->id]);

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.hardcopy.reverse', $case), ['reason' => 'Logged against the wrong file'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.hardcopy_reversed')->sole();
    $metadata = json_decode($audit->metadata, true);

    expect(caseRow9b($case)->hardcopy_received_on)->toBeNull()
        ->and($metadata['reason'])->toBe('Logged against the wrong file')
        ->and($metadata['previous_received_on'])->toBe(poSToday())
        ->and($metadata['previous_recorded_by'])->toBe($recorder->id);
});

it('refuses to reverse a hardcopy that is not recorded', function () {
    $case = submittedCase9b();

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.hardcopy.reverse', $case), ['reason' => 'Nothing to reverse here'])
        ->assertSessionHasErrors('reason');

    expect(auditCount9b($case, 'case.hardcopy_reversed'))->toBe(0);
});

it('returns 404 to a vendor of another organisation and 403 to staff without hardcopy.confirm', function () {
    $case = submittedCase9b();
    $technocrat = caseUser(Role::Technocrat);
    assign($case, Role::Technocrat, $technocrat);

    $this->actingAs(vendorUser(vendorOrg()))->post(route('cases.hardcopy.store', $case), ['received_on' => poSToday()])->assertNotFound();
    $this->actingAs($technocrat)->post(route('cases.hardcopy.store', $case), ['received_on' => poSToday()])->assertForbidden();

    expect(caseRow9b($case)->hardcopy_received_on)->toBeNull();
});

it('throttles the actions at 20 a minute per user', function () {
    $case = submittedCase9b();
    $cmo = caseUser(Role::Cmo);

    foreach (range(1, 20) as $i) {
        $this->actingAs($cmo)->post(route('cases.hardcopy.reverse', $case), ['reason' => 'Nothing recorded yet'])->assertRedirect();
    }

    $this->actingAs($cmo)->post(route('cases.hardcopy.reverse', $case), ['reason' => 'Nothing recorded yet'])->assertStatus(429);
});

// 5. Props ------------------------------------------------------------------------

it('gives the staff view the hardcopy record and can flags from the policy', function () {
    $case = submittedCase9b();
    $registry = caseUser(Role::CmoRegistry, ['name' => 'Registry Officer']);
    $technocrat = caseUser(Role::Technocrat);
    assign($case, Role::Technocrat, $technocrat);

    $before = pageProps($this->actingAs($registry)->get(route('cases.show', $case)));
    $this->actingAs($registry)->post(route('cases.intake', $case));
    $this->actingAs($registry)->post(route('cases.hardcopy.store', $case), ['received_on' => poSToday()]);
    $after = pageProps($this->actingAs($registry)->get(route('cases.show', $case)));
    $tech = pageProps($this->actingAs($technocrat)->get(route('cases.show', $case)));

    expect($before['can'])->toMatchArray(['intake' => true, 'record_hardcopy' => true])
        ->and($before['hardcopy'])->toBeNull()
        ->and($before['hardcopy_dates']['today'])->toBe(poSToday())
        ->and($after['can'])->toMatchArray(['intake' => false, 'record_hardcopy' => true])
        ->and($after['hardcopy'])->toMatchArray(['received_on' => poSToday(), 'recorded_by' => 'Registry Officer'])
        ->and($after['case']['status_label'])->toBe('CMO intake')
        ->and(collect($after['timeline'])->last()['to_label'])->toBe('CMO intake')
        ->and($tech['can'])->toMatchArray(['intake' => false, 'record_hardcopy' => false]);
});

it('shows the vendor the hardcopy date only: no names, reasons or ids', function () {
    $org = vendorOrg();
    $case = submittedCase9b(['org_id' => $org->id]);
    $vendor = vendorUser($org);
    $cmo = caseUser(Role::Cmo, ['name' => 'Staff Name Must Not Leak']);

    $before = pageProps($this->actingAs($vendor)->get(route('requests.show', $case))->assertOk());
    expect($before['case']['hardcopy_received_on'])->toBeNull();

    $this->actingAs($cmo)->post(route('cases.hardcopy.store', $case), ['received_on' => poSToday()])->assertSessionHasNoErrors();
    $this->actingAs($cmo)->post(route('cases.hardcopy.reverse', $case), ['reason' => 'Correction reason must not leak']);
    $this->actingAs($cmo)->post(route('cases.hardcopy.store', $case), ['received_on' => poSToday()]);

    $props = pageProps($this->actingAs($vendor)->get(route('requests.show', $case))->assertOk());
    $scan = scanProps(collect($props)->except(['auth'])->all());

    expect($props['case']['hardcopy_received_on'])->toBe(poSToday());
    expectVendorSafe($props);
    foreach (['recorded_by', 'recorded_at', 'hardcopy_received_by', 'hardcopy_received_at', 'hardcopy_receipt_method', 'reason', 'previous_received_on'] as $key) {
        expect($scan['keys'])->not->toContain($key);
    }
    foreach ($scan['strings'] as $string) {
        expect($string)->not->toContain('Staff Name Must Not Leak')
            ->and($string)->not->toContain('Correction reason must not leak');
    }
});

it('keeps the actions component free of ad hoc network and storage calls', function () {
    $files = glob(resource_path('js/components/cases/*.tsx'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $source = file_get_contents($file);

        foreach (['fetch(', 'axios', 'XMLHttpRequest', 'localStorage', 'sessionStorage', 'IndexedDB', 'indexedDB', 'dangerouslySetInnerHTML'] as $banned) {
            expect(str_contains($source, $banned))->toBeFalse(basename($file)." contains {$banned}");
        }
    }
});
