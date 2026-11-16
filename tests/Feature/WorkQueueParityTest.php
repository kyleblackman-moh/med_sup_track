<?php

use App\Console\Commands\DevSampleCase;
use App\Enums\Role;
use App\Http\Resources\Cases\StaffCaseDetail;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\WorkQueue;
use App\Workflow\TransitionRules;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// The dashboard queue and the case page agree: for every stage dev:sample-case
// can reach (real services, not hand-built rows) and every staff dev user, a
// case is in that user's queue exactly when the matching action flag on the
// staff case detail DTO is true. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('documents');
    config(['documents.scanner' => 'eicar_dev']);

    // One user per desk, so the command finds a user even on an empty database.
    vendorUser(vendorOrg());
    foreach ([Role::CmoRegistry, Role::Cmo, Role::Technocrat, Role::CabOfficer, Role::LegalOfficer, Role::PsOffice] as $role) {
        caseUser($role);
    }
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** The staff dev users the command itself picks (DevSampleCase::findUser). @return array<string, User> */
function parityDevUsers(): array
{
    $find = Closure::bind(fn (string $key) => $this->findUser($key), app(DevSampleCase::class), DevSampleCase::class);

    return collect(['registry', 'cmo', 'technocrat', 'cab', 'legal', 'ps'])
        ->mapWithKeys(fn (string $key) => [$key => $find($key)])
        ->filter()->all();
}

function paritySample(): ProcurementCase
{
    $caseId = DB::table('case_requisitions')->where('item_description', 'like', '[[]'.trim(DevSampleCase::TAG, '[]').']%')
        ->orderByDesc('id')->value('case_id');

    return ProcurementCase::withoutGlobalScopes()->findOrFail($caseId);
}

/**
 * Queue step => is it due, by the case page's action flags. Hardcopy and gate
 * add the conditions the queue shows on top of the flag: the case page also
 * offers a hardcopy correction once received, and shows "mark ready" with a
 * gate tracker while the gate is still open.
 *
 * @return list<string>
 */
function stepsByDetailFlags(ProcurementCase $case, array $can): array
{
    $due = [
        'intake' => $can['intake'],
        'hardcopy' => $can['record_hardcopy'] && ! $case->hardcopyReceived(),
        'forward' => $can['forward_for_review'],
        'gate' => $can['mark_ready_for_file'] && TransitionRules::gateMissing($case) === [],
        'prepare' => $can['prepare_file'],
        'legal' => $can['send_to_legal'],
        'returned' => $can['route_onward'],
        'letter' => $can['issue_letter'],
        'close' => $can['close_case'],
    ];

    return collect($due)->filter()->keys()->sort()->values()->all();
}

it('queues a case for a user exactly when the case page offers the matching action', function (string $stage) {
    app()->detectEnvironment(fn () => 'local');
    $this->artisan('dev:sample-case', ['stage' => $stage])->assertSuccessful();
    $case = paritySample();

    $users = parityDevUsers();
    expect($users)->toHaveCount(6);

    foreach ($users as $key => $user) {
        $this->actingAs($user);
        $fresh = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);

        $queued = collect(WorkQueue::for($user)['items'] ?? [])
            ->where('case_id', $case->id)->pluck('step')->sort()->values()->all();
        $expected = stepsByDetailFlags($fresh, StaffCaseDetail::for($fresh, $user)['can']);

        expect($queued)->toBe($expected, "stage {$stage}, user {$key}");
    }
})->with(array_keys(DevSampleCase::STAGES));
