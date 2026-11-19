<?php

use App\Console\Commands\DevSampleCase;
use App\Enums\CaseStatus as S;
use App\Enums\QueryStatus;
use App\Enums\Role;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Models\QueryRoute;
use App\Models\User;
use App\Queries\QueryAccess;
use App\Workflow\Actions\RecordLegalDecision;
use App\Workflow\Actions\RecordPsDecision;
use App\Workflow\NextStep;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\QueryRouteSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// OVERNIGHT-2 (F1): end to end through the REAL routes and actions. The vendor's
// draft, documents and submit come from dev:sample-case (the real services);
// every staff step after that is an HTTP request to the real route, and the
// Next Step card is checked at every stage. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    $this->seed(QueryRouteSeeder::class);
    Storage::fake('documents');
    Notification::fake();
    config(['documents.scanner' => 'eicar_dev']);

    // One user per desk, so the command finds a user even on an empty database.
    vendorUser(vendorOrg());
    foreach ([Role::CmoRegistry, Role::Cmo, Role::Technocrat, Role::CabOfficer, Role::LegalOfficer, Role::PsOffice] as $role) {
        caseUser($role);
    }
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
});

/** The users dev:sample-case itself picks. @return array<string, User> */
function e2eUsers(): array
{
    $find = Closure::bind(fn (string $key) => $this->findUser($key), app(DevSampleCase::class), DevSampleCase::class);

    return collect(['vendor', 'registry', 'cmo', 'technocrat', 'cab', 'legal', 'ps'])
        ->mapWithKeys(fn (string $key) => [$key => $find($key)])->all();
}

/** A fresh sample case at $stage, made by the real services. */
function e2eSample(string $stage): ProcurementCase
{
    app()->detectEnvironment(fn () => 'local');
    test()->artisan('dev:sample-case', ['stage' => $stage])->assertSuccessful();
    // Back to testing, so the real routes run as in every other feature test (CSRF is skipped only there).
    app()->detectEnvironment(fn () => 'testing');

    $caseId = DB::table('case_requisitions')->where('item_description', 'like', '[[]'.trim(DevSampleCase::TAG, '[]').']%')
        ->orderByDesc('id')->value('case_id');

    return ProcurementCase::withoutGlobalScopes()->findOrFail($caseId);
}

function e2eFresh(ProcurementCase $case): ProcurementCase
{
    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

/** The Next Step card $user sees: stage, holder, instruction, and the action labels (primary first). */
function e2eCard(ProcurementCase $case, User $user): array
{
    test()->actingAs($user);
    $next = NextStep::for(e2eFresh($case), $user);
    $actions = collect($next['actions'])->sortByDesc('primary')->pluck('label')->values()->all();

    return ['stage' => $next['stage'], 'holder' => $next['holder'], 'instruction' => $next['instruction'], 'actions' => $actions];
}

/** POST a real staff action and expect it to succeed. */
function e2ePost(User $user, string $route, ProcurementCase $case, array $data = []): void
{
    test()->actingAs($user)->from(route('cases.show', $case))->post(route($route, $case), $data)
        ->assertSessionHasNoErrors()->assertRedirect();
}

it('takes a request from submission to closed through the real actions', function () {
    $u = e2eUsers();
    $case = e2eSample('submitted');
    $vendorName = (string) $case->organisation()->value('name');
    $today = CarbonImmutable::now('America/Port_of_Spain')->toDateString();

    expect(e2eFresh($case)->status)->toBe(S::SUBMITTED)
        ->and(e2eCard($case, $u['cmo']))->toBe([
            'stage' => 'Submitted',
            'holder' => "CMO's Office",
            'instruction' => 'The CMO Registry accepts the request into intake and records the hardcopy when it arrives.',
            'actions' => ['Accept Into Intake'],
        ]);

    // Intake, then the hardcopy.
    e2ePost($u['cmo'], 'cases.intake', $case);
    expect(e2eCard($case, $u['cmo'])['stage'])->toBe('CMO Intake')
        ->and(e2eCard($case, $u['cmo'])['actions'])->toBe(['Send to Technocrats and CAB']);
    e2ePost($u['cmo'], 'cases.hardcopy.store', $case, ['received_on' => $today]);
    expect(e2eFresh($case)->hardcopyReceived())->toBeTrue();

    // Send to the Technocrats and CAB.
    e2ePost($u['cmo'], 'cases.forward', $case, ['technocrats' => [$u['technocrat']->id], 'cab_officers' => [$u['cab']->id]]);
    $card = e2eCard($case, $u['cmo']);
    expect($card['stage'])->toBe('In Review')
        ->and($card['holder'])->toBe('Technocrats and CAB')
        ->and($card['actions'])->toBe(['Mark Ready for File']);
    $this->actingAs($u['cmo']);
    $ready = collect(NextStep::for(e2eFresh($case), $u['cmo'])['actions'])->firstWhere('key', 'mark_ready_for_file');
    expect($ready['enabled'])->toBeFalse()
        ->and($ready['disabled_reason'])->toContain('Technical Consideration')->toContain($u['technocrat']->name)
        ->and(e2eCard($case, $u['technocrat'])['actions'])->toBe(['Record Technical Consideration'])
        ->and(e2eCard($case, $u['cab'])['actions'])->toBe(['Record Funding Confirmation']);

    // Both clear.
    e2ePost($u['technocrat'], 'cases.technical', $case, ['outcome' => 'favourable']);
    e2ePost($u['cab'], 'cases.funding', $case, ['outcome' => 'confirmed']);
    $this->actingAs($u['cmo']);
    expect(collect(NextStep::for(e2eFresh($case), $u['cmo'])['actions'])->firstWhere('key', 'mark_ready_for_file')['enabled'])->toBeTrue();

    e2ePost($u['cmo'], 'cases.ready', $case);
    expect(e2eCard($case, $u['cmo']))->toMatchArray(['stage' => 'Ready for File', 'actions' => ['Prepare File']]);

    e2ePost($u['cmo'], 'cases.prepare', $case, ['file_no' => config('cases.file_no_prefix').'/E2E']);
    expect(e2eCard($case, $u['cmo']))->toMatchArray(['stage' => 'File Prepared', 'actions' => ['Send to Legal Unit']]);

    e2ePost($u['cmo'], 'cases.legal.send', $case, ['legal_officers' => [$u['legal']->id]]);
    expect(e2eCard($case, $u['legal']))->toMatchArray(['stage' => 'With Legal', 'holder' => 'Legal Unit', 'actions' => ['Record Legal Decision']])
        ->and(e2eCard($case, $u['cmo'])['actions'])->toBe([]);

    e2ePost($u['legal'], 'cases.legal.decide', $case, ['decision' => RecordLegalDecision::CONFIRMED]);
    expect(e2eCard($case, $u['ps']))->toMatchArray(['stage' => 'With PS', 'holder' => 'PS Office', 'actions' => ['Record PS Decision']]);

    e2ePost($u['ps'], 'cases.ps.decide', $case, ['decision' => RecordPsDecision::GRANT]);
    $card = e2eCard($case, $u['cmo']);
    expect($card['stage'])->toBe('Non-Objection Granted')
        ->and($card['actions'])->toBe(["Issue Letter to {$vendorName}"])
        ->and($card['instruction'])->toContain($vendorName);

    e2ePost($u['cmo'], 'cases.letter.issue', $case, ['letter_date' => $today, 'letter_reference' => 'E2E/LETTER/1', 'emailed_on' => $today]);
    expect(e2eCard($case, $u['cmo']))->toMatchArray(['stage' => 'Letter Issued', 'actions' => ['Close Case']]);

    e2ePost($u['cmo'], 'cases.close', $case);
    expect(e2eFresh($case)->status)->toBe(S::CLOSED)
        ->and(e2eCard($case, $u['cmo']))->toMatchArray(['stage' => 'Closed', 'holder' => null, 'actions' => []]);

    // The vendor's Progress timeline at the end: vendor labels only.
    $this->actingAs($u['vendor'])->get(route('requests.show', $case))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('progress', fn ($progress) => collect($progress)->pluck('label')->all()
                === ['Submitted', 'Under Review', 'Hardcopy Received', 'Letter Issued', 'Closed']));
});

it('runs a Legal query through the CMO to the Technocrats and back', function () {
    $u = e2eUsers();
    $case = e2eSample('with_legal');
    $route = QueryRoute::query()->where('from_role', 'legal_officer')->where('to_role', 'technocrat')->firstOrFail();
    $staff = fn (User $user) => $this->actingAs($user)->get(route('cases.show', [$case, 'tab' => 'queries']))->assertOk()->viewData('page')['props']['queries']['queries'];

    // 1. Legal raises a query to the Technocrats: held for the CMO.
    $this->actingAs($u['legal'])->post(route('cases.queries.store', $case), ['route' => $route->id, 'body' => 'Clause 7 needs the technical basis.'])
        ->assertSessionHasNoErrors();
    $query = CaseQuery::withoutGlobalScopes()->where('case_id', $case->id)->firstOrFail();
    expect($query->status)->toBe(QueryStatus::AWAITING_FORWARD)
        ->and($staff($u['technocrat']))->toBe([])
        ->and(e2eCard($case, $u['cmo']))->toHaveKey('stage', 'With Legal');
    $this->actingAs($u['cmo']);
    expect(NextStep::for(e2eFresh($case), $u['cmo'])['queries'][0]['action'])->toBe('Forward Query to Technocrats');

    // 2. The CMO forwards it to the Technocrats.
    $held = QueryMessage::query()->where('query_id', $query->id)->firstOrFail();
    $this->actingAs($u['cmo'])->post(route('cases.queries.forward', [$case, $query->id, $held->id]))->assertSessionHasNoErrors();
    expect($staff($u['technocrat'])[0]['messages'][0]['body'])->toBe('Clause 7 needs the technical basis.');
    $this->actingAs($u['technocrat']);
    expect(NextStep::for(e2eFresh($case), $u['technocrat'])['queries'][0]['action'])->toBe('Answer Query');

    // 3. The Technocrats answer: held for the CMO again.
    $this->actingAs($u['technocrat'])->post(route('cases.queries.reply', [$case, $query->id]), ['body' => 'It follows the formulary listing.'])
        ->assertSessionHasNoErrors();
    expect(collect($staff($u['legal'])[0]['messages'])->pluck('body')->all())->not->toContain('It follows the formulary listing.');

    // 4. The CMO forwards the answer back to Legal.
    $answer = QueryMessage::query()->where('query_id', $query->id)->whereNull('forwarded_at')->firstOrFail();
    $this->actingAs($u['cmo']);
    expect(NextStep::for(e2eFresh($case), $u['cmo'])['queries'][0]['action'])->toBe('Forward Query to Legal Unit');
    $this->post(route('cases.queries.forward', [$case, $query->id, $answer->id]))->assertSessionHasNoErrors();

    expect(collect($staff($u['legal'])[0]['messages'])->pluck('body')->all())->toBe(['Clause 7 needs the technical basis.', 'It follows the formulary listing.'])
        ->and(CaseQuery::withoutGlobalScopes()->find($query->id)->status)->toBe(QueryStatus::ANSWERED)
        // The query never moved the case.
        ->and(e2eFresh($case)->status)->toBe(S::WITH_LEGAL);

    // Legal can still decide while (or after) the query runs.
    e2ePost($u['legal'], 'cases.legal.decide', $case, ['decision' => RecordLegalDecision::CONFIRMED]);
    expect(e2eFresh($case)->status)->toBe(S::WITH_PS);
});
