<?php

use App\Enums\CaseStatus as S;
use App\Enums\ReviewDesk;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Models\CaseAssignment;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\SendToLegal;
use App\Workflow\NextStep;
use App\Workflow\StageWording;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// NEXT-STEP-1 (Step 4): the Next Step DTO over status x permission. Actions
// come from TransitionRules through CasePolicy::act; words from StageWording.
// DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** The eight staff actors of the brief. Reviewers, Legal and registry are assigned to each case they act on. */
function nsActors(): array
{
    return [
        'cmo' => caseUser(Role::Cmo, ['name' => 'NS CMO']),
        'technocrat' => caseUser(Role::Technocrat, ['name' => 'NS Tech '.Str::upper(Str::random(4))]),
        'cab' => caseUser(Role::CabOfficer, ['name' => 'NS CAB '.Str::upper(Str::random(4))]),
        'legal' => caseUser(Role::LegalOfficer, ['name' => 'NS Legal '.Str::upper(Str::random(4))]),
        'ps' => caseUser(Role::PsOffice, ['name' => 'NS PS']),
        'registry' => caseUser(Role::CmoRegistry, ['name' => 'NS Registry']),
        'system_admin' => caseUser(Role::SystemAdmin),
        'auditor' => caseUser(Role::Auditor),
    ];
}

function nsRow(ProcurementCase $case, string $role, ?User $user, ?string $purpose): void
{
    (new CaseAssignment)->forceFill([
        'case_id' => $case->id, 'role' => $role, 'purpose' => $purpose,
        'user_id' => $user?->id, 'assigned_at' => CarbonImmutable::now('UTC')->subDay(),
    ])->save();
}

/** A case at $status, with every desk assigned (so visibility is never why an action is missing). */
function nsCase(S $status, array $actors, array $attributes = []): ProcurementCase
{
    $case = makeCase([
        'status' => $status,
        'reference_no' => 'NR-NS-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(6),
        'technical_track' => $status === S::SUBMITTED || $status === S::CMO_INTAKE ? null : TrackStatus::FAVOURABLE,
        'funding_track' => $status === S::SUBMITTED || $status === S::CMO_INTAKE ? null : TrackStatus::CONFIRMED,
        'hardcopy_received_on' => CarbonImmutable::now('America/Port_of_Spain')->subDays(2)->toDateString(),
        'hardcopy_received_at' => CarbonImmutable::now('UTC')->subDays(2),
        'hardcopy_received_by' => $actors['registry']->id,
        'file_no' => in_array($status, [S::READY_FOR_FILE, S::IN_REVIEW, S::CMO_INTAKE, S::SUBMITTED], true) ? null : 'HE:NS/1',
        ...$attributes,
    ]);

    nsRow($case, Role::CmoRegistry->value, null, null);
    nsRow($case, ReviewDesk::Technical->assignmentRole(), $actors['technocrat'], ReviewDesk::Technical->value);
    nsRow($case, ReviewDesk::Funding->assignmentRole(), $actors['cab'], ReviewDesk::Funding->value);
    nsRow($case, SendToLegal::assignmentRole(), $actors['legal'], SendToLegal::PURPOSE);

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

function nsKeys(array $next): array
{
    return array_column($next['actions'], 'key');
}

/** status => [actor => expected action keys]; anyone not listed has none. */
function nsMatrix(): array
{
    return [
        'Submitted' => [S::SUBMITTED, ['cmo' => ['intake'], 'registry' => ['intake']]],
        'CMO Intake' => [S::CMO_INTAKE, ['cmo' => ['forward_for_review']]],
        'In Review' => [S::IN_REVIEW, ['cmo' => ['mark_ready_for_file'], 'technocrat' => ['record_technical'], 'cab' => ['record_funding']]],
        'Ready for File' => [S::READY_FOR_FILE, ['cmo' => ['prepare_file']]],
        'File Prepared' => [S::FILE_PREPARED, ['cmo' => ['send_to_legal']]],
        'With Legal' => [S::WITH_LEGAL, ['legal' => ['legal_decision']]],
        'With PS' => [S::WITH_PS, ['ps' => ['ps_decision']]],
        'Non-Objection Granted' => [S::NON_OBJECTION_GRANTED, ['cmo' => ['issue_letter']]],
        'Letter Issued' => [S::LETTER_ISSUED, ['cmo' => ['close_case']]],
        'Returned to CMO' => [S::RETURNED_TO_CMO, ['cmo' => ['route_technocrats', 'route_legal']]],
        'Closed' => [S::CLOSED, []],
    ];
}

const NS_HOLDERS = [
    'SUBMITTED' => "CMO's Office", 'CMO_INTAKE' => "CMO's Office", 'IN_REVIEW' => 'Technocrats and CAB',
    'READY_FOR_FILE' => "CMO's Office", 'FILE_PREPARED' => "CMO's Office", 'WITH_LEGAL' => 'Legal Unit',
    'WITH_PS' => 'PS Office', 'NON_OBJECTION_GRANTED' => "CMO's Office", 'LETTER_ISSUED' => "CMO's Office",
    'RETURNED_TO_CMO' => "CMO's Office", 'CLOSED' => null,
];

// 1. The matrix -----------------------------------------------------------------

it('gives each actor exactly the actions their permission allows, and everyone the holder and instruction', function (S $status, array $expected) {
    $actors = nsActors();
    $case = nsCase($status, $actors);

    foreach ($actors as $name => $user) {
        $next = NextStep::for($case, $user);

        expect(nsKeys($next))->toBe($expected[$name] ?? [], "{$status->value} as {$name}")
            ->and($next['holder'])->toBe(NS_HOLDERS[$status->value])
            ->and($next['stage'])->toBe(StageWording::STAGES[$status->value]['stage'])
            ->and($next['instruction'])->toBeString()->not->toBe('');

        if ($status !== S::RETURNED_TO_CMO) {
            expect($next['instruction'])->toBe(StageWording::vendor(StageWording::STAGES[$status->value]['instruction'], $case->organisation->name));
        }

        // At most one primary, and only when there are actions.
        expect(count(array_filter(array_column($next['actions'], 'primary'))))->toBe($next['actions'] === [] ? 0 : 1);
    }
})->with(nsMatrix());

it('never gives a Technocrat a CMO action', function () {
    $actors = nsActors();

    foreach (nsMatrix() as [$status]) {
        $keys = nsKeys(NextStep::for(nsCase($status, $actors), $actors['technocrat']));
        expect(array_diff($keys, ['record_technical']))->toBe([], $status->value);
    }
});

it('gives system_admin and the auditor no case page and no actions', function (string $who) {
    $actors = nsActors();
    $case = nsCase(S::IN_REVIEW, $actors);

    // The case list is 403 (no case permission); the case itself is hidden by the visibility scope before that, so 404.
    $this->actingAs($actors[$who])->get(route('cases.index'))->assertForbidden();
    $this->actingAs($actors[$who])->get(route('cases.show', $case))->assertNotFound();
    expect(NextStep::for($case, $actors[$who])['actions'])->toBe([]);
})->with(['system_admin', 'auditor']);

// 2. Destinations, labels and confirmations --------------------------------------

it('labels each forwarding button with its destination and repeats it in the confirmation', function (S $status, string $actor, string $key, ?string $destination) {
    $actors = nsActors();
    $case = nsCase($status, $actors);
    $destination ??= $case->organisation->name; // the letter goes to the vendor

    $action = collect(NextStep::for($case, $actors[$actor])['actions'])->firstWhere('key', $key);

    expect($action['destination'])->toBe($destination)
        ->and($action['label'])->toEndWith($destination)
        ->and($action['confirm']['button'])->toBe($action['label'])
        ->and($action['confirm']['title'])->toContain($destination)
        ->and($action['confirm']['body'])->not->toBe('')
        // On a returned case the suggested route is the primary one (Technocrats after a Legal query).
        ->and($action['primary'])->toBe($key !== 'route_legal');
})->with([
    'intake to review' => [S::CMO_INTAKE, 'cmo', 'forward_for_review', 'Technocrats and CAB'],
    'file to Legal' => [S::FILE_PREPARED, 'cmo', 'send_to_legal', 'Legal Unit'],
    'returned to Technocrats' => [S::RETURNED_TO_CMO, 'cmo', 'route_technocrats', 'Technocrats'],
    'returned to Legal' => [S::RETURNED_TO_CMO, 'cmo', 'route_legal', 'Legal Unit'],
    'letter to vendor' => [S::NON_OBJECTION_GRANTED, 'cmo', 'issue_letter', null],
]);

it('takes the vendor name from the organisation', function () {
    $actors = nsActors();
    $case = nsCase(S::NON_OBJECTION_GRANTED, $actors);
    $case->organisation->forceFill(['name' => 'Renamed Supplies Ltd '.Str::random(4)])->save();
    $case->unsetRelation('organisation');
    $name = $case->organisation()->value('name');

    $next = NextStep::for($case, $actors['cmo']);

    expect($next['actions'][0]['label'])->toBe('Issue Letter to '.$name)
        ->and($next['instruction'])->toContain($name);
});

it('does not claim an email in any confirmation', function (S $status) {
    $actors = nsActors();
    $case = nsCase($status, $actors);

    foreach ($actors as $user) {
        foreach (NextStep::for($case, $user)['actions'] as $action) {
            expect(Str::lower($action['confirm']['body']))->not->toContain('will be emailed')
                ->and(Str::lower($action['confirm']['body']))->not->toContain('notif');
        }
    }
})->with(array_values(array_filter(array_map(fn (array $row) => $row[0], nsMatrix()), fn (S $s) => ! $s->isTerminal())));

it('offers a returned case only the destinations the rules allow, suggesting Technocrats after a Legal query', function () {
    $actors = nsActors();
    $case = nsCase(S::RETURNED_TO_CMO, $actors);
    (new CaseTransition)->forceFill([
        'case_id' => $case->id, 'from_status' => S::WITH_LEGAL, 'to_status' => S::RETURNED_TO_CMO,
        'actor_id' => $actors['legal']->id, 'reason' => 'Query about the contract terms',
    ])->save();

    $next = NextStep::for($case, $actors['cmo']);
    $primary = collect($next['actions'])->firstWhere('primary', true);

    expect(nsKeys($next))->not->toContain('route_ps')
        ->and($primary['key'])->toBe('route_technocrats')
        ->and($primary['route'])->toBe('technocrats')
        ->and($next['instruction'])->toContain('Legal Unit')->toContain('Technocrats');
});

// 3. The In Review gate ------------------------------------------------------------

it('blocks Mark Ready for File until hardcopy, technical and funding are all done, naming who holds each missing one', function () {
    $actors = nsActors();
    $case = nsCase(S::IN_REVIEW, $actors, [
        'technical_track' => TrackStatus::PENDING,
        'hardcopy_received_on' => null, 'hardcopy_received_at' => null, 'hardcopy_received_by' => null,
    ]);

    $next = NextStep::for($case, $actors['cmo']);
    $ready = collect($next['actions'])->firstWhere('key', 'mark_ready_for_file');
    $checklist = collect($next['checklist'])->keyBy('key');

    expect($ready['enabled'])->toBeFalse()
        ->and($ready['disabled_reason'])->toContain('Technical Consideration')
        ->toContain('Technocrats')->toContain($actors['technocrat']->name)
        ->toContain('Hardcopy Received')->toContain("CMO's Office")
        ->not->toContain('Funding')
        ->and($checklist->keys()->all())->toBe(['hardcopy', 'technical', 'funding'])
        ->and($checklist['funding']['done'])->toBeTrue()
        ->and($checklist['technical']['done'])->toBeFalse()
        ->and($checklist['technical']['people'])->toBe([$actors['technocrat']->name])
        ->and($checklist['funding']['holder'])->toBe('CAB')
        // The CMO may record the hardcopy, so the inline form is there.
        ->and($checklist['hardcopy']['hardcopy_form'])->not->toBeNull();

    // A Technocrat sees the same checklist but no form and no CMO button.
    $tech = NextStep::for($case, $actors['technocrat']);
    expect(collect($tech['checklist'])->firstWhere('key', 'hardcopy')['hardcopy_form'])->toBeNull()
        ->and(nsKeys($tech))->toBe(['record_technical']);

    // All three done: enabled, no reason.
    $done = nsCase(S::IN_REVIEW, $actors);
    $ready = collect(NextStep::for($done, $actors['cmo'])['actions'])->firstWhere('key', 'mark_ready_for_file');
    expect($ready['enabled'])->toBeTrue()->and($ready['disabled_reason'])->toBeNull();
});

it('names the assigned people and the plain date the file arrived', function () {
    $actors = nsActors();
    $case = nsCase(S::WITH_LEGAL, $actors);
    (new CaseTransition)->forceFill([
        'case_id' => $case->id, 'from_status' => S::FILE_PREPARED, 'to_status' => S::WITH_LEGAL,
        'actor_id' => $actors['cmo']->id, 'created_at' => CarbonImmutable::parse('2026-03-04 15:00:00', 'UTC'),
    ])->save();

    $next = NextStep::for($case, $actors['ps']);

    expect($next['assigned'])->toBe([$actors['legal']->name])
        ->and($next['since'])->toBe('2026-03-04')
        ->and($next['since_label'])->toBe('4 Mar 2026')
        ->and($next['actions'])->toBe([]);
});

// 4. The page and the vendor --------------------------------------------------------

it('sends the Next Step to the staff case page', function () {
    $actors = nsActors();
    $case = nsCase(S::CMO_INTAKE, $actors);

    $props = pageProps($this->actingAs($actors['cmo'])->get(route('cases.show', $case))->assertOk());

    expect($props['next_step'])->toBe(NextStep::for($case, $actors['cmo']))
        ->and($props['next_step']['actions'][0]['label'])->toBe('Send to Technocrats and CAB');
});

it('never gives a vendor any of the Next Step', function (S $status) {
    $actors = nsActors();
    $case = nsCase($status, $actors);
    $vendor = vendorUser($case->organisation()->first());

    $this->actingAs($vendor)->get(route('cases.show', $case))->assertForbidden();

    $props = Arr::except(pageProps($this->actingAs($vendor)->get(route('requests.show', $case))->assertOk()), ['quote']);
    $scan = scanProps($props);
    foreach (['next_step', 'checklist', 'disabled_reason', 'since_label', 'holder', 'assigned'] as $key) {
        expect($scan['keys'])->not->toContain($key);
    }
    $words = [StageWording::TECHNOCRATS_AND_CAB, StageWording::LEGAL_UNIT, StageWording::PS_OFFICE, StageWording::CMO_OFFICE, 'Where This File Is',
        ...array_column(StageWording::STAGES, 'instruction')];
    foreach ($scan['strings'] as $string) {
        foreach ($words as $word) {
            expect($string)->not->toContain($word);
        }
    }
})->with([S::IN_REVIEW, S::WITH_LEGAL, S::WITH_PS, S::RETURNED_TO_CMO]);

// 5. Static guard: React renders the DTO and holds no status or destination names ------

it('keeps status and destination names out of the case React files', function () {
    $root = base_path('resources/js');
    $all = [...glob("{$root}/components/cases/*.tsx"), "{$root}/pages/cases/show.tsx"];
    $actionFiles = array_map(fn (string $f) => "{$root}/components/cases/{$f}.tsx",
        ['next-step-card', 'case-actions', 'forward-for-review', 'legal-actions', 'ps-actions', 'letter-actions', 'review-actions']);

    $codes = array_map(fn (S $s) => $s->value, S::cases());
    foreach ($all as $file) {
        $source = file_get_contents($file);
        foreach ($codes as $code) {
            expect(preg_match("/['\"`]{$code}['\"`]/", $source))->toBe(0, basename($file)." holds the status code {$code}");
        }
    }

    $names = [StageWording::CMO_OFFICE, StageWording::TECHNOCRATS_AND_CAB, StageWording::LEGAL_UNIT, StageWording::PS_OFFICE, 'PS office', 'the CMO'];
    foreach ($actionFiles as $file) {
        $source = file_get_contents($file);
        foreach ($names as $name) {
            expect($source)->not->toContain($name, basename($file)." holds the destination \"{$name}\"");
        }
        foreach (StageWording::STAGES as $entry) {
            expect($source)->not->toContain($entry['instruction']);
        }
    }
});
