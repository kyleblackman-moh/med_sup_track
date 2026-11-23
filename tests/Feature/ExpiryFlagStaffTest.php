<?php

use App\Enums\Role;
use App\Models\ProcurementCase;
use App\Submissions\ExpiryRisk;
use App\Submissions\SubmitCase;
use App\Support\WorkQueue;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-23: when days on hand is more than the days until the earliest
// expiry, the CMO, the Technocrats and the PS see a flag on the case page, in the
// Cases list and in the work queue. Advisory: it never stops the request.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
    Queue::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A submitted request whose stock is $quantity (AMC 30 = 1 a day), one batch expiring in $days. */
function flaggedCase(string $quantity, int $days): ProcurementCase
{
    [$case, $vendor] = readyDraft('AD_HOC_EMERGENCY');
    $today = CarbonImmutable::now('America/Port_of_Spain')->startOfDay();
    saveDraft($case, $vendor, [
        'requisition' => ['average_monthly_consumption' => '30', 'no_stock_on_hand' => false],
        'stock_expiries' => [['quantity' => $quantity, 'expiry_date' => $today->addDays($days)->toDateString()]],
    ]);
    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

it('flags the case page for the CMO, the assigned Technocrat and the PS', function () {
    $case = flaggedCase('100', 10); // 100 days on hand, expires in 10
    $technocrat = caseUser(Role::Technocrat);
    assign($case, Role::Technocrat, $technocrat);

    foreach ([caseUser(Role::Cmo), $technocrat, caseUser(Role::PsOffice)] as $user) {
        $flag = pageProps($this->actingAs($user)->get(route('cases.show', $case))->assertOk())['case']['expiry_flag'] ?? null;

        expect($flag)->toBe([
            'label' => 'Stock may expire before use',
            'tone' => 'warning',
            'detail' => 'About 100 days of stock on hand; the earliest batch expires in 10 days.',
        ]);
    }
});

it('puts the flag on the Cases list row and the work queue row', function () {
    $flagged = flaggedCase('100', 10);
    $fine = flaggedCase('5', 10);
    $cmo = caseUser(Role::Cmo);

    $rows = collect(pageProps($this->actingAs($cmo)->get(route('cases.index', ['q' => $flagged->reference_no])))['cases']['data'] ?? []);
    expect($rows->firstWhere('id', $flagged->id)['expiry_flag']['label'] ?? null)->toBe('Stock may expire before use');

    $rows = collect(pageProps($this->actingAs($cmo)->get(route('cases.index', ['q' => $fine->reference_no])))['cases']['data'] ?? []);
    expect($rows->firstWhere('id', $fine->id))->toHaveKey('expiry_flag', null);

    Auth::login($cmo);
    $items = collect(WorkQueue::for($cmo)['items'] ?? []);
    expect($items->firstWhere('case_id', $flagged->id)['expiry_flag']['label'] ?? null)->toBe('Stock may expire before use')
        ->and($items->firstWhere('case_id', $fine->id))->toHaveKey('expiry_flag', null);
});

it('reports expired stock on its own', function () {
    $case = flaggedCase('5', 10);
    expect(DB::table('case_stock_expiries')->where('case_id', $case->id)->update(['expiry_date' => '2020-01-01']))->toBe(1);
    // As a signed-in staff member, as on every page (case child rows are scoped to the viewer).
    Auth::login(caseUser(Role::Cmo));

    expect(ExpiryRisk::flag(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)))->toBe([
        'label' => 'Expired stock reported',
        'tone' => 'warning',
        'detail' => '1 batch is past its expiry date.',
    ]);
});

it('does not cost a query per row on the Cases list', function () {
    flaggedCase('100', 10);
    $cmo = caseUser(Role::Cmo);
    $this->actingAs($cmo)->get(route('cases.index'))->assertOk();

    DB::enableQueryLog();
    $this->actingAs($cmo)->get(route('cases.index'))->assertOk();
    $few = count(DB::getQueryLog());

    flaggedCase('100', 10);
    flaggedCase('100', 10);
    DB::flushQueryLog();
    $this->actingAs($cmo)->get(route('cases.index'))->assertOk();

    expect(count(DB::getQueryLog()))->toBe($few);
});
