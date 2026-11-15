<?php

use App\Console\Commands\DevSampleCase;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\TransitionRules;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// Slice DEV-1: the dev:sample-case command. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('documents');
    config(['documents.scanner' => 'eicar_dev']);

    // One dev user per desk, so the tests do not depend on the dev seeder.
    vendorUser(vendorOrg());
    foreach ([Role::CmoRegistry, Role::Cmo, Role::Technocrat, Role::CabOfficer, Role::LegalOfficer, Role::PsOffice] as $role) {
        caseUser($role);
    }
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function asLocal(): void
{
    app()->detectEnvironment(fn () => 'local');
}

function sampleCount(): int
{
    return DB::table('case_requisitions')->where('item_description', 'like', '[[]DEV SAMPLE]%')->count();
}

function latestSample(): ProcurementCase
{
    $caseId = DB::table('case_requisitions')->where('item_description', 'like', '[[]DEV SAMPLE]%')->orderByDesc('id')->value('case_id');

    return ProcurementCase::withoutGlobalScopes()->findOrFail($caseId);
}

it('refuses to run outside the local environment', function () {
    expect(app()->environment())->toBe('testing');
    $before = sampleCount();

    $this->artisan('dev:sample-case', ['stage' => 'submitted'])
        ->expectsOutputToContain('only runs when APP_ENV is local')
        ->assertFailed();

    expect(sampleCount())->toBe($before);
});

it('refuses an unknown stage', function () {
    asLocal();
    $before = sampleCount();

    $this->artisan('dev:sample-case', ['stage' => 'reopened'])->expectsOutputToContain('Unknown stage')->assertFailed();

    expect(sampleCount())->toBe($before);
});

it('drives a new sample case to each stage along a valid path', function (string $stage, CaseStatus $expected) {
    asLocal();
    $before = sampleCount();

    $this->artisan('dev:sample-case', ['stage' => $stage])
        ->expectsOutputToContain('Sample case ready at stage '.$stage)
        ->assertSuccessful();

    $case = latestSample();
    $path = DB::table('case_transitions')->where('case_id', $case->id)->orderBy('id')->get(['from_status', 'to_status']);

    expect(sampleCount())->toBe($before + 1)
        ->and($case->status)->toBe($expected)
        ->and($case->reference_no)->not->toBeNull()
        ->and($path->first()->from_status)->toBe('DRAFT')
        ->and($path->last()->to_status)->toBe($expected->value);

    // Every step is a rule in TransitionRules, and each starts where the last ended.
    $at = 'DRAFT';
    foreach ($path as $row) {
        expect($row->from_status)->toBe($at)
            ->and(TransitionRules::find(CaseStatus::from($row->from_status), CaseStatus::from($row->to_status)))->not->toBeNull();
        $at = $row->to_status;
    }

    // Documents went through the real upload and scan.
    expect(DB::table('documents')->where('case_id', $case->id)->where('scan_status', 'CLEAN')->count())->toBeGreaterThan(0);
})->with([
    'submitted' => ['submitted', CaseStatus::SUBMITTED],
    'intake' => ['intake', CaseStatus::CMO_INTAKE],
    'in_review' => ['in_review', CaseStatus::IN_REVIEW],
    'ready_for_file' => ['ready_for_file', CaseStatus::READY_FOR_FILE],
    'file_prepared' => ['file_prepared', CaseStatus::FILE_PREPARED],
    'with_legal' => ['with_legal', CaseStatus::WITH_LEGAL],
    'with_ps' => ['with_ps', CaseStatus::WITH_PS],
    'returned_by_legal' => ['returned_by_legal', CaseStatus::RETURNED_TO_CMO],
    // Slice 10d.
    'ps_granted' => ['ps_granted', CaseStatus::NON_OBJECTION_GRANTED],
    'returned_by_ps_legal' => ['returned_by_ps_legal', CaseStatus::RETURNED_TO_CMO],
    'returned_by_ps_technical' => ['returned_by_ps_technical', CaseStatus::RETURNED_TO_CMO],
    // Slice 10f.
    'letter_issued' => ['letter_issued', CaseStatus::LETTER_ISSUED],
    'closed' => ['closed', CaseStatus::CLOSED],
]);

it('records the sample letter on the letter stages', function (string $stage) {
    asLocal();

    $this->artisan('dev:sample-case', ['stage' => $stage])->assertSuccessful();

    $case = latestSample();
    expect($case->letter_date)->not->toBeNull()
        ->and($case->letter_reference)->toBe(DevSampleCase::LETTER_REFERENCE)
        ->and($case->letter_emailed_on)->not->toBeNull()
        ->and($case->closed_at === null)->toBe($stage === 'letter_issued');
})->with(['letter_issued', 'closed']);

it('ends the PS return stages with the matching return_kind and the sample reason', function (string $stage, string $kind) {
    asLocal();

    $this->artisan('dev:sample-case', ['stage' => $stage])->assertSuccessful();

    $last = DB::table('case_transitions')->where('case_id', latestSample()->id)->orderByDesc('id')->first();
    expect($last->from_status)->toBe('WITH_PS')
        ->and($last->to_status)->toBe('RETURNED_TO_CMO')
        ->and($last->return_kind)->toBe($kind)
        ->and($last->reason)->toBe(DevSampleCase::PS_RETURN_REASON);
})->with([
    'legal' => ['returned_by_ps_legal', 'legal'],
    'technical' => ['returned_by_ps_technical', 'technical'],
]);

it('ends returned_by_legal at RETURNED_TO_CMO with the reason as the transition reason', function () {
    asLocal();

    $this->artisan('dev:sample-case', ['stage' => 'returned_by_legal'])->assertSuccessful();

    $case = latestSample();
    $last = DB::table('case_transitions')->where('case_id', $case->id)->orderByDesc('id')->first();

    expect($case->status)->toBe(CaseStatus::RETURNED_TO_CMO)
        ->and($last->from_status)->toBe('WITH_LEGAL')
        ->and($last->reason)->toBe(DevSampleCase::RETURN_REASON)
        ->and($case->hardcopyReceived())->toBeTrue()
        ->and($case->file_no)->toBe(config('cases.file_no_prefix'));

    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk());
    expect($props['returned'])->toMatchArray(['source' => 'Legal', 'reason' => DevSampleCase::RETURN_REASON]);
});

it('names a missing dev user and creates nothing', function () {
    asLocal();
    // Inside the test transaction only: no active Legal officer.
    User::permission(Permission::LegalConfirm->value)->update(['status' => User::STATUS_DISABLED]);
    $before = sampleCount();
    $cases = DB::table('cases')->count();

    $this->artisan('dev:sample-case', ['stage' => 'with_legal'])
        ->expectsOutputToContain('Missing dev user(s): a Legal officer')
        ->assertFailed();

    expect(sampleCount())->toBe($before)->and(DB::table('cases')->count())->toBe($cases);
});

it('reports the refused step and leaves the case where it stopped', function () {
    asLocal();
    // For this test only: a blank file series, so PrepareFile refuses the file number.
    config(['cases.file_no_prefix' => '']);

    $this->artisan('dev:sample-case', ['stage' => 'file_prepared'])
        ->expectsOutputToContain('Step "prepare"')
        ->expectsOutputToContain('The case was left where it stopped')
        ->assertFailed();

    expect(latestSample()->status)->toBe(CaseStatus::READY_FOR_FILE);
});
