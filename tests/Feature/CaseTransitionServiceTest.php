<?php

use App\Enums\CaseStatus;
use App\Enums\Permission as PermissionName;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Events\CaseTransitioned;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\CaseTransitionService;
use App\Workflow\Exceptions\GateNotPassed;
use App\Workflow\Exceptions\InvalidTransition;
use App\Workflow\Exceptions\ReasonRequired;
use App\Workflow\Exceptions\StaleTransition;
use App\Workflow\TransitionRule;
use App\Workflow\TransitionRules;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

// Slice 7: CaseTransitionService, the rules table and the T4 gate.
// Runs inside DatabaseTransactions; case/user helpers are in tests/Support/CaseHelpers.php.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    // Slice 8d: the T1 case is a ready draft (procurement types and clean documents).
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
    Event::fake([CaseTransitioned::class]);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** [code => [from[], to]] from spec 6.5 plus T17. */
const EXPECTED_RULES = [
    'T1' => [['DRAFT'], 'SUBMITTED'],
    'T2' => [['SUBMITTED'], 'CMO_INTAKE'],
    'T3' => [['CMO_INTAKE'], 'IN_REVIEW'],
    'T4' => [['IN_REVIEW'], 'READY_FOR_FILE'],
    'T5' => [['READY_FOR_FILE'], 'FILE_PREPARED'],
    'T6' => [['FILE_PREPARED'], 'WITH_LEGAL'],
    'T7' => [['WITH_LEGAL'], 'WITH_PS'],
    'T8' => [['WITH_LEGAL'], 'RETURNED_TO_CMO'],
    'T9' => [['WITH_PS'], 'NON_OBJECTION_GRANTED'],
    'T10' => [['WITH_PS'], 'RETURNED_TO_CMO'],
    'T11' => [['RETURNED_TO_CMO'], 'IN_REVIEW'],
    'T12' => [['RETURNED_TO_CMO'], 'WITH_LEGAL'],
    'T13' => [['NON_OBJECTION_GRANTED'], 'LETTER_ISSUED'],
    'T14' => [['LETTER_ISSUED'], 'CLOSED'],
    'T15' => [['DRAFT', 'SUBMITTED', 'IN_REVIEW'], 'WITHDRAWN'],
    'T16' => [['WITH_PS', 'RETURNED_TO_CMO'], 'REJECTED_FINAL'],
    'T17' => [['RETURNED_TO_CMO'], 'WITH_PS'],
];

/** Expected new current_holder_role per rule (spec 6.2). */
const EXPECTED_HOLDERS = [
    'T1' => 'cmo_registry', 'T2' => 'cmo', 'T3' => null, 'T4' => 'cmo', 'T5' => 'cmo',
    'T6' => 'legal_officer', 'T7' => 'ps_office', 'T8' => 'cmo', 'T9' => 'cmo', 'T10' => 'cmo',
    'T11' => null, 'T12' => 'legal_officer', 'T13' => 'cmo', 'T14' => null, 'T15' => null,
    'T16' => null, 'T17' => 'ps_office',
];

const EXPECTED_PENDING_GUARDS = [
    'T1' => [], // slice 8d: replaced by the real guard
    'T2' => [],
    'T3' => ['assignments_created'],
    'T4' => [],
    'T5' => [], // slice 10c: replaced by the real file_no guard (T6 has it too)
    'T6' => [],
    'T7' => ['no_open_legal_queries'],
    'T8' => ['legal_query_created'],
    'T9' => ['no_open_ps_queries'],
    'T10' => ['ps_query_created'],
    'T11' => [],
    'T12' => ['query_answered_and_forwarded'],
    'T13' => ['letter_generated'],
    'T14' => ['dispatch_confirmed'],
    'T15' => [],
    'T16' => [],
    'T17' => ['query_answered_and_forwarded'],
];

function rule(string $code): TransitionRule
{
    return collect(TransitionRules::all())->firstWhere('code', $code)
        ?? throw new RuntimeException("no rule {$code}");
}

function enabledCodes(): array
{
    return collect(TransitionRules::all())->filter->enabled->pluck('code')->all();
}

function service(): CaseTransitionService
{
    return app(CaseTransitionService::class);
}

/** Attributes that satisfy the T4 gate. */
function gatePassed(): array
{
    return [
        'technical_track' => TrackStatus::FAVOURABLE,
        'funding_track' => TrackStatus::CONFIRMED,
        ...hardcopyColumns(true),
    ];
}

/**
 * Slice 10c: "received" means the date is set, and the CHECK constraint needs
 * the date, recorder and recorded-at columns together (all set or all null).
 */
function hardcopyColumns(bool $received): array
{
    return [
        'hardcopy_received_on' => $received ? now('America/Port_of_Spain')->toDateString() : null,
        'hardcopy_received_at' => $received ? now() : null,
        'hardcopy_received_by' => $received ? caseUser(Role::CmoRegistry)->id : null,
    ];
}

/** A case sitting in the rule's first from-status, with the gate satisfied for T4. */
function caseFor(TransitionRule $rule, array $attributes = []): ProcurementCase
{
    // Slice 8d: T1 is guarded (clean readiness and a reference number), so its
    // case is a ready draft with a reference, as SubmitCase would prepare it.
    if ($rule->code === 'T1' && $attributes === []) {
        [$case] = readyDraft();
        DB::table('cases')->where('id', $case->id)->update(['reference_no' => 'NR-1999-'.Str::upper(Str::random(6))]);

        return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
    }

    return makeCase([
        'status' => $rule->from[0],
        ...($rule->code === 'T4' ? gatePassed() : []),
        // Slice 10c: T5 and T6 require a file number (PrepareFile writes it before T5).
        ...(in_array($rule->code, ['T5', 'T6'], true) ? ['file_no' => 'HE:8/2/50/TEST'] : []),
        ...$attributes,
    ]);
}

/**
 * A user who holds the rule's permission and is eligible under CasePolicy::act():
 * a view_all holder as is, a vendor of the case's org, or an ACTIVE assignment.
 */
function actorFor(TransitionRule $rule, ProcurementCase $case): User
{
    $roles = SpatieRole::with('permissions')->orderBy('name')->get()
        ->filter(fn ($r) => $r->permissions->contains('name', $rule->permission));

    // Slice 9a: staff never see a DRAFT, so only the owning vendor can act on one.
    if ($case->status === CaseStatus::DRAFT && $roles->contains('name', Role::VendorApplicant->value)) {
        return vendorUser($case->organisation()->first());
    }

    $viewAll = $roles->first(fn ($r) => $r->permissions->contains('name', PermissionName::CaseViewAll->value));
    if ($viewAll) {
        return caseUser($viewAll->name);
    }

    if ($roles->contains('name', Role::VendorApplicant->value)) {
        return vendorUser($case->organisation()->first());
    }

    $role = $roles->first() ?? throw new RuntimeException("no role holds {$rule->permission}");
    $user = caseUser($role->name);
    assign($case, $role->name, $user);

    return $user;
}

function reasonFor(TransitionRule $rule): ?string
{
    return $rule->requiresReason ? 'Documented reason' : null;
}

function transitionRows(ProcurementCase $case): int
{
    return DB::table('case_transitions')->where('case_id', $case->id)->count();
}

function auditRows(ProcurementCase $case): int
{
    return DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.transitioned')->count();
}

function statusInDb(ProcurementCase $case): string
{
    return DB::table('cases')->where('id', $case->id)->value('status');
}

/** Asserts that nothing at all was written for $case. */
function expectNothingWritten(ProcurementCase $case, string $status): void
{
    expect(statusInDb($case))->toBe($status)
        ->and(transitionRows($case))->toBe(0)
        ->and(auditRows($case))->toBe(0);
    Event::assertNotDispatched(CaseTransitioned::class);
}

// 1. Registry -------------------------------------------------------------------

it('defines T1-T17 with the expected from and to statuses', function () {
    $actual = collect(TransitionRules::all())->mapWithKeys(fn (TransitionRule $r) => [
        $r->code => [array_map(fn (CaseStatus $s) => $s->value, $r->from), $r->to->value],
    ])->all();

    expect($actual)->toBe(EXPECTED_RULES);
});

it('disables T16 so find() treats it as nonexistent', function () {
    expect(rule('T16')->enabled)->toBeFalse()
        ->and(TransitionRules::find(CaseStatus::WITH_PS, CaseStatus::REJECTED_FINAL))->toBeNull()
        ->and(TransitionRules::find(CaseStatus::RETURNED_TO_CMO, CaseStatus::REJECTED_FINAL))->toBeNull()
        ->and(enabledCodes())->not->toContain('T16');
});

it('has no two enabled rules for the same (from, to) pair', function () {
    $pairs = collect(TransitionRules::all())->filter->enabled
        ->flatMap(fn (TransitionRule $r) => array_map(fn ($f) => $f->value.'>'.$r->to->value, $r->from));

    expect($pairs->duplicates()->all())->toBe([]);
});

it('uses only seeded permissions', function () {
    $seeded = Permission::pluck('name')->all();

    foreach (TransitionRules::all() as $r) {
        expect($seeded)->toContain($r->permission);
    }
});

it('sets the expected current_holder_role for each rule', function () {
    $holders = collect(TransitionRules::all())->mapWithKeys(fn ($r) => [$r->code => $r->holderRole])->all();

    expect($holders)->toBe(EXPECTED_HOLDERS);
});

it('lists exactly the pending guards from the brief', function () {
    $pending = collect(TransitionRules::all())->mapWithKeys(fn ($r) => [$r->code => $r->pendingGuards])->all();

    expect($pending)->toBe(EXPECTED_PENDING_GUARDS);
});

// 2. Happy path ------------------------------------------------------------------

it('performs each enabled rule and records it once', function (string $code) {
    $rule = rule($code);
    $case = caseFor($rule);
    $actor = actorFor($rule, $case);

    $result = service()->transition($case, $rule->to, $actor, reasonFor($rule));

    expect($result->status)->toBe($rule->to)
        ->and($result->current_holder_role)->toBe($rule->holderRole)
        ->and(statusInDb($case))->toBe($rule->to->value);

    $row = DB::table('case_transitions')->where('case_id', $case->id)->sole();
    expect($row->from_status)->toBe($rule->from[0]->value)
        ->and($row->to_status)->toBe($rule->to->value)
        ->and((int) $row->actor_id)->toBe($actor->id)
        ->and($row->acting_for_id)->toBeNull()
        ->and($row->reason)->toBe(reasonFor($rule));

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.transitioned')->sole();
    // The reason text stays in case_transitions; the audit row has only a flag and a length.
    expect(json_decode($audit->metadata, true))->toBe([
        'from' => $rule->from[0]->value, 'to' => $rule->to->value,
        'has_reason' => reasonFor($rule) !== null, 'reason_length' => mb_strlen((string) reasonFor($rule)),
        'rule' => $code,
    ])->and((int) $audit->actor_user_id)->toBe($actor->id)
        ->and($audit->subject_type)->toBe('case')
        ->and($audit->subject_id)->toBe($case->id);

    Event::assertDispatchedTimes(CaseTransitioned::class, 1);
    Event::assertDispatched(CaseTransitioned::class, fn (CaseTransitioned $e) => $e->case->id === $case->id
        && $e->from === $rule->from[0] && $e->to === $rule->to && $e->actor->id === $actor->id);
})->with(fn () => enabledCodes());

// 3. Permission denied -------------------------------------------------------------

it('rejects a user without the permission and writes nothing', function (string $code, Role $role) {
    $rule = rule($code);
    $case = caseFor($rule);
    $user = caseUser($role);

    expect(fn () => service()->transition($case, $rule->to, $user, 'Some reason'))
        ->toThrow(AuthorizationException::class);

    expectNothingWritten($case, $rule->from[0]->value);
})->with(fn () => enabledCodes())->with([Role::Auditor, Role::SystemAdmin]);

// 4. Illegal pairs -----------------------------------------------------------------

it('rejects every status pair without an enabled rule', function () {
    $enabled = collect(TransitionRules::all())->filter->enabled
        ->flatMap(fn ($r) => array_map(fn ($f) => $f->value.'>'.$r->to->value, $r->from))->all();

    $cmo = caseUser(Role::Cmo);
    $case = makeCase();
    $checked = 0;

    foreach (CaseStatus::cases() as $from) {
        foreach (CaseStatus::cases() as $to) {
            if (in_array($from->value.'>'.$to->value, $enabled, true)) {
                continue;
            }

            DB::table('cases')->where('id', $case->id)->update(['status' => $from->value]);
            $case->refresh();

            try {
                service()->transition($case, $to, $cmo, 'Some reason');
                $this->fail("{$from->value} -> {$to->value} was allowed");
            } catch (InvalidTransition $e) {
                expect($e->from)->toBe($from)->and($e->to)->toBe($to);
            }
            $checked++;
        }
    }

    expect($checked)->toBe(14 * 14 - count($enabled))
        ->and(transitionRows($case))->toBe(0)
        ->and(auditRows($case))->toBe(0);
});

// 5. Gate ---------------------------------------------------------------------------

it('passes the T4 gate only when all three conditions hold', function (bool $technical, bool $funding, bool $hardcopy) {
    $case = makeCase([
        'status' => CaseStatus::IN_REVIEW,
        'technical_track' => $technical ? TrackStatus::FAVOURABLE : TrackStatus::PENDING,
        'funding_track' => $funding ? TrackStatus::CONFIRMED : TrackStatus::QUERY_OPEN,
        ...hardcopyColumns($hardcopy),
    ]);
    $cmo = caseUser(Role::Cmo);

    if ($technical && $funding && $hardcopy) {
        expect(service()->transition($case, CaseStatus::READY_FOR_FILE, $cmo)->status)->toBe(CaseStatus::READY_FOR_FILE);

        return;
    }

    $expectedMissing = array_values(array_filter([
        $technical ? null : 'technical',
        $funding ? null : 'funding',
        $hardcopy ? null : 'hardcopy',
    ]));

    try {
        service()->transition($case, CaseStatus::READY_FOR_FILE, $cmo);
        $this->fail('gate passed');
    } catch (GateNotPassed $e) {
        expect($e->missing)->toBe($expectedMissing);
    }

    expectNothingWritten($case, 'IN_REVIEW');
})->with([true, false])->with([true, false])->with([true, false]);

it('blocks the gate for both PENDING and QUERY_OPEN tracks', function (TrackStatus $blocking) {
    $cmo = caseUser(Role::Cmo);

    foreach (['technical_track', 'funding_track'] as $track) {
        $case = makeCase(['status' => CaseStatus::IN_REVIEW, ...gatePassed(), $track => $blocking]);

        expect(fn () => service()->transition($case, CaseStatus::READY_FOR_FILE, $cmo))
            ->toThrow(GateNotPassed::class);
    }
})->with([TrackStatus::PENDING, TrackStatus::QUERY_OPEN]);

it('blocks the gate when a track is null (never routed to review)', function () {
    $case = makeCase(['status' => CaseStatus::IN_REVIEW, ...hardcopyColumns(true)]);

    expect(fn () => service()->transition($case, CaseStatus::READY_FOR_FILE, caseUser(Role::Cmo)))
        ->toThrow(GateNotPassed::class);
});

// 6. Reasons ------------------------------------------------------------------------

it('requires a reason where the rule says so', function (string $code, ?string $reason) {
    $rule = rule($code);
    $case = caseFor($rule);
    $actor = actorFor($rule, $case);

    expect(fn () => service()->transition($case, $rule->to, $actor, $reason))->toThrow(ReasonRequired::class);
    expectNothingWritten($case, $rule->from[0]->value);
})->with(['T8', 'T10', 'T11', 'T15'])->with([null, '', "  \t\n "]);

it('marks exactly T8, T10, T11, T15 (and the disabled T16) as needing a reason', function () {
    // Spec 6.5 requires a reason on T16 too; it is disabled until Q3 is decided.
    expect(collect(TransitionRules::all())->filter->requiresReason->pluck('code')->all())
        ->toBe(['T8', 'T10', 'T11', 'T15', 'T16']);
});

it('accepts a null reason for rules that do not need one', function () {
    $rule = rule('T2');
    $case = caseFor($rule);

    service()->transition($case, $rule->to, actorFor($rule, $case), null);

    expect(DB::table('case_transitions')->where('case_id', $case->id)->value('reason'))->toBeNull();
});

// 7. Atomicity ----------------------------------------------------------------------

it('rolls everything back when the audit write fails', function () {
    $case = makeCase(['status' => CaseStatus::CMO_INTAKE]);
    $cmo = caseUser(Role::Cmo);

    // Fail the audit insert: the listener runs right after the INSERT executes,
    // so the exception comes out of Audit::record() inside the transaction.
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs')) {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => service()->transition($case, CaseStatus::IN_REVIEW, $cmo))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    $row = DB::table('cases')->where('id', $case->id)->first();
    expect($row->status)->toBe('CMO_INTAKE')
        ->and($row->current_holder_role)->toBeNull()
        ->and($row->technical_track)->toBeNull()
        ->and($row->funding_track)->toBeNull()
        ->and(transitionRows($case))->toBe(0);
    Event::assertNotDispatched(CaseTransitioned::class);
});

// 8. Staleness ----------------------------------------------------------------------

it('refuses a transition chosen from a stale status', function () {
    $case = makeCase(['status' => CaseStatus::SUBMITTED]);
    $cmo = caseUser(Role::Cmo);

    // Someone else moves the case on behind this model's back.
    DB::table('cases')->where('id', $case->id)->update(['status' => 'CMO_INTAKE']);

    expect(fn () => service()->transition($case, CaseStatus::CMO_INTAKE, $cmo))
        ->toThrow(StaleTransition::class);

    expectNothingWritten($case, 'CMO_INTAKE');
});

// 9. Eligibility --------------------------------------------------------------------

it('refuses a user whose assignment is completed', function () {
    $case = makeCase(['status' => CaseStatus::WITH_LEGAL]);
    $legal = caseUser(Role::LegalOfficer);
    assign($case, Role::LegalOfficer, $legal, completed: true);

    expect(fn () => service()->transition($case, CaseStatus::WITH_PS, $legal))
        ->toThrow(AuthorizationException::class);
    expectNothingWritten($case, 'WITH_LEGAL');
});

it('refuses a user with the permission but no assignment (case invisible to them)', function () {
    $case = makeCase(['status' => CaseStatus::WITH_LEGAL]);

    expect(fn () => service()->transition($case, CaseStatus::WITH_PS, caseUser(Role::LegalOfficer)))
        ->toThrow(AuthorizationException::class);
    expectNothingWritten($case, 'WITH_LEGAL');
});

it('refuses a visible case when the assignment is for another user', function () {
    $case = makeCase(['status' => CaseStatus::SUBMITTED]);
    $registry = caseUser(Role::CmoRegistry);
    assign($case, Role::CmoRegistry, caseUser(Role::CmoRegistry));
    assign($case, Role::CmoRegistry, $registry, completed: true); // visible, read-only

    expect(fn () => service()->transition($case, CaseStatus::CMO_INTAKE, $registry))
        ->toThrow(AuthorizationException::class);
    expectNothingWritten($case, 'SUBMITTED');
});

it('refuses any transition out of a terminal status', function (CaseStatus $terminal) {
    $case = makeCase(['status' => $terminal]);

    foreach (CaseStatus::cases() as $to) {
        expect(fn () => service()->transition($case, $to, caseUser(Role::Cmo), 'Some reason'))
            ->toThrow(InvalidTransition::class);
    }
    expectNothingWritten($case, $terminal->value);
})->with([CaseStatus::CLOSED, CaseStatus::WITHDRAWN, CaseStatus::REJECTED_FINAL]);

// 10. Withdrawal --------------------------------------------------------------------

it('lets a vendor of the owning org withdraw DRAFT and SUBMITTED only', function (CaseStatus $from, bool $allowed) {
    $org = vendorOrg();
    $case = makeCase(['org_id' => $org->id, 'status' => $from]);
    $vendor = vendorUser($org);

    if ($allowed) {
        expect(service()->transition($case, CaseStatus::WITHDRAWN, $vendor, 'No longer needed')->status)
            ->toBe(CaseStatus::WITHDRAWN);

        return;
    }

    expect(fn () => service()->transition($case, CaseStatus::WITHDRAWN, $vendor, 'No longer needed'))
        ->toThrow(AuthorizationException::class);
    expectNothingWritten($case, $from->value);
})->with([
    'DRAFT' => [CaseStatus::DRAFT, true],
    'SUBMITTED' => [CaseStatus::SUBMITTED, true],
    'IN_REVIEW' => [CaseStatus::IN_REVIEW, false],
]);

it('does not let a vendor of another org withdraw', function () {
    $case = makeCase(['status' => CaseStatus::DRAFT]);

    expect(fn () => service()->transition($case, CaseStatus::WITHDRAWN, vendorUser(vendorOrg()), 'Reason'))
        ->toThrow(AuthorizationException::class);
    expectNothingWritten($case, 'DRAFT');
});

it('lets staff with case.withdraw withdraw SUBMITTED and IN_REVIEW cases', function (CaseStatus $from) {
    $case = makeCase(['status' => $from]);

    expect(service()->transition($case, CaseStatus::WITHDRAWN, caseUser(Role::Cmo), 'Withdrawn on request')->status)
        ->toBe(CaseStatus::WITHDRAWN);
})->with([CaseStatus::SUBMITTED, CaseStatus::IN_REVIEW]);

// Slice 9a: staff never see a DRAFT, so they cannot withdraw one either.
it('does not let staff withdraw a DRAFT', function () {
    $case = makeCase(['status' => CaseStatus::DRAFT]);

    expect(fn () => service()->transition($case, CaseStatus::WITHDRAWN, caseUser(Role::Cmo), 'Withdrawn on request'))
        ->toThrow(AuthorizationException::class);
    expectNothingWritten($case, 'DRAFT');
});

// 11. Track resets ------------------------------------------------------------------

it('sets both tracks to PENDING on T3', function () {
    $case = makeCase(['status' => CaseStatus::CMO_INTAKE]);

    $result = service()->transition($case, CaseStatus::IN_REVIEW, caseUser(Role::Cmo));

    expect($result->technical_track)->toBe(TrackStatus::PENDING)
        ->and($result->funding_track)->toBe(TrackStatus::PENDING)
        ->and($result->current_holder_role)->toBeNull();
});

it('resets only technical_track on T11', function () {
    $case = makeCase(['status' => CaseStatus::RETURNED_TO_CMO, ...gatePassed()]);
    $hardcopy = DB::table('cases')->where('id', $case->id)->value('hardcopy_received_at');

    $result = service()->transition($case, CaseStatus::IN_REVIEW, caseUser(Role::Cmo), 'Technical issue raised by PS');

    expect($result->technical_track)->toBe(TrackStatus::PENDING)
        ->and($result->funding_track)->toBe(TrackStatus::CONFIRMED)
        ->and(DB::table('cases')->where('id', $case->id)->value('hardcopy_received_at'))->toBe($hardcopy);
});

// 11b. Reason text stays out of audit_logs ----------------------------------------

it('keeps the reason text out of every audit row and in case_transitions.reason', function () {
    $case = makeCase(['status' => CaseStatus::RETURNED_TO_CMO, ...gatePassed()]);
    $reason = 'Zebra-quartz reason 7731: unique text that must not reach audit_logs';

    service()->transition($case, CaseStatus::IN_REVIEW, caseUser(Role::Cmo), $reason);

    $audits = DB::table('audit_logs')->where('case_id', $case->id)->get();
    $transitioned = $audits->firstWhere('action', 'case.transitioned');

    expect($audits)->not->toBeEmpty()
        ->and($audits->filter(fn ($row) => str_contains((string) $row->metadata, 'Zebra-quartz')))->toBeEmpty()
        ->and(json_decode($transitioned->metadata, true))->toMatchArray(['has_reason' => true, 'reason_length' => mb_strlen($reason)])
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->value('reason'))->toBe($reason);
});

// 12. Model guard -------------------------------------------------------------------

it('blocks status and holder writes outside the service', function (string $column, mixed $value, bool $force) {
    $case = makeCase(['status' => CaseStatus::DRAFT]);

    $write = function () use ($case, $column, $value, $force) {
        $force ? $case->forceFill([$column => $value]) : $case->{$column} = $value;
        $case->save();
    };

    expect($write)->toThrow(LogicException::class);
    expect(DB::table('cases')->where('id', $case->id)->value($column))->toBe($column === 'status' ? 'DRAFT' : null);
})->with([
    'status, property' => ['status', CaseStatus::CLOSED, false],
    'status, forceFill' => ['status', CaseStatus::CLOSED, true],
    'holder, property' => ['current_holder_role', 'cmo', false],
    'holder, forceFill' => ['current_holder_role', 'cmo', true],
]);

it('still allows other column updates and the initial insert', function () {
    $case = makeCase(['status' => CaseStatus::SUBMITTED, 'current_holder_role' => 'cmo_registry']);
    $case->title = 'Renamed';
    $case->save();

    expect(DB::table('cases')->where('id', $case->id)->first())
        ->title->toBe('Renamed')
        ->status->toBe('SUBMITTED')
        ->current_holder_role->toBe('cmo_registry');
});

it('restores the guard after allowStatusWrite, even when the callback throws', function () {
    $case = makeCase();

    expect(fn () => ProcurementCase::allowStatusWrite(fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    $case->status = CaseStatus::CLOSED;
    expect(fn () => $case->save())->toThrow(LogicException::class);

    // Nested blocks keep the outer permission until the outer block ends.
    ProcurementCase::allowStatusWrite(function () use ($case) {
        ProcurementCase::allowStatusWrite(fn () => null);
        $case->save();
    });
    expect(statusInDb($case))->toBe('CLOSED');
});

// 14. Seeder ------------------------------------------------------------------------

it('seeds the permissions added for slice 7', function () {
    $holders = fn (string $permission) => SpatieRole::with('permissions')->orderBy('name')->get()
        ->filter(fn ($r) => $r->permissions->contains('name', $permission))->pluck('name')->all();

    expect($holders('case.withdraw'))->toBe(['cmo', 'vendor_applicant'])
        ->and($holders('case.reject_final'))->toBe(['cmo']);
});

it('keeps CaseTransition rows written by the service insert-only', function () {
    $case = makeCase(['status' => CaseStatus::SUBMITTED]);
    service()->transition($case, CaseStatus::CMO_INTAKE, caseUser(Role::Cmo));

    $row = CaseTransition::where('case_id', $case->id)->sole();
    expect(fn () => $row->delete())->toThrow(LogicException::class);
});
