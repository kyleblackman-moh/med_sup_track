<?php

use App\Enums\Role;
use App\Models\CaseStockExpiry;
use App\Models\ProcurementCase;
use App\Submissions\ExpiryRisk;
use App\Submissions\RequisitionData;
use App\Submissions\SaveRequisition;
use App\Submissions\SubmissionReadiness;
use App\Submissions\SubmitCase;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// Slice V2-1: days on hand and the expiry warning (ExpiryRisk). Advisory only.

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

const RISK_TODAY = '2026-11-17';

/** An unsaved batch: $days from RISK_TODAY to its expiry date. */
function riskBatch(int $id, string $quantity, int $days): CaseStockExpiry
{
    return (new CaseStockExpiry)->forceFill([
        'id' => $id,
        'quantity' => $quantity,
        'expiry_date' => CarbonImmutable::parse(RISK_TODAY)->addDays($days)->toDateString(),
    ]);
}

/**
 * Kyle, 2026-11-24: the quantity on hand is typed, not the batch total. These rule tests
 * pass the batches' quantities summed as that typed quantity (0 for "no stock on hand").
 */
function risk(?string $amc, array $batches, bool $noStock = false): array
{
    $onHand = $noStock ? '0' : collect($batches)->reduce(fn (string $sum, CaseStockExpiry $b) => bcadd($sum, (string) ($b->quantity ?? '0'), 2), '0');

    return ExpiryRisk::compute($amc, $onHand, collect($batches), CarbonImmutable::parse(RISK_TODAY));
}

// 1. The rule --------------------------------------------------------------------------

it('agrees with the simple rule for one batch', function (string $quantity, int $days, bool $atRisk) {
    // AMC 300 = 10 a day. Simple rule: quantity / 10 days of cover against the days left.
    $result = risk('300', [riskBatch(1, $quantity, $days)]);

    expect(isset($result['expiry_warnings'][1]))->toBe($atRisk);
    if ($atRisk) {
        expect($result['expiry_warnings'][1])->toBe(['state' => 'at_risk', 'days_until_expiry' => $days, 'cover_days' => bcdiv($quantity, '10', 2)]);
    }
})->with([
    '10 days of cover, expires in 5' => ['100', 5, true],
    '10 days of cover, expires in 20' => ['100', 20, false],
    '45.5 days of cover, expires in 45' => ['455', 45, true],
]);

// Kyle, 2026-11-23: the rule is days on hand (all stock) > days until the earliest expiry.
// It replaces the per-batch rule, which did not flag the first case below.
it('flags days on hand above the days until the earliest expiry, across batches', function () {
    // 10 a day. A: 50 units, expires in 10 days. B: 500 units, expires in 200 days.
    // 550 units = 55 days on hand > 10 days to the earliest expiry: flagged, on A.
    $result = risk('300', [riskBatch(2, '500', 200), riskBatch(1, '50', 10)]);

    expect($result['expiry_warnings'])->toBe([1 => ['state' => 'at_risk', 'days_until_expiry' => 10, 'cover_days' => '55.00']])
        ->and($result['days_on_hand'])->toBe('55.00');
});

it('compares against the earliest expiry only, and flags the request once', function () {
    // 10 a day. A: 80 units in 10 days. B: 50 units in 12 days: 130 units = 13 days > 10.
    $result = risk('300', [riskBatch(1, '80', 10), riskBatch(2, '50', 12)]);

    expect($result['expiry_warnings'])->toBe([1 => ['state' => 'at_risk', 'days_until_expiry' => 10, 'cover_days' => '13.00']]);

    // 130 units = 13 days, earliest expiry in 13 days: not more, no flag.
    expect(risk('300', [riskBatch(1, '80', 13), riskBatch(2, '50', 40)])['expiry_warnings'])->toBe([]);
});

it('does not flag exact equality, and compares exactly', function (string $amc, string $quantity, int $days, bool $atRisk) {
    expect(isset(risk($amc, [riskBatch(1, $quantity, $days)])['expiry_warnings'][1]))->toBe($atRisk);
})->with([
    '10 days of cover, expires in 10' => ['300', '100', 10, false],
    '30 days of cover (AMC 7, 7 units), expires in 30' => ['7', '7', 30, false],
    '30.000...4 days of cover, expires in 30' => ['7', '7.01', 30, true],
    '3.33... days of cover, expires in 3' => ['90', '10', 3, true],
    '3.33... days of cover, expires in 4' => ['90', '10', 4, false],
    'expires today with stock left' => ['300', '1', 0, true],
]);

it('marks a batch past its expiry date as expired', function () {
    // 10 a day. Expired: 100 units (yesterday). Next: 50 units in 10 days.
    // Days on hand counts all stock: 150 units = 15 days > 10 days to the earliest unexpired batch.
    $result = risk('300', [riskBatch(1, '100', -1), riskBatch(2, '50', 10)]);

    expect($result['expiry_warnings'])->toBe([
        1 => ['state' => 'expired', 'days_until_expiry' => -1, 'cover_days' => '15.00'], // 2026-11-24: the whole stock
        2 => ['state' => 'at_risk', 'days_until_expiry' => 10, 'cover_days' => '15.00'],
    ])->and($result['days_on_hand'])->toBe('15.00');

    // With only 5 days on hand in all, only the expired batch is reported.
    expect(risk('300', [riskBatch(1, '20', -1), riskBatch(2, '30', 10)])['expiry_warnings'])
        ->toBe([1 => ['state' => 'expired', 'days_until_expiry' => -1, 'cover_days' => '5.00']]);
});

it('gives no warning and no days on hand when AMC is 0 or missing, or there is no stock', function (?string $amc, bool $noStock) {
    expect(risk($amc, [riskBatch(1, '100', -1), riskBatch(2, '100', 1)], $noStock))
        ->toBe(['days_on_hand' => null, 'expiry_warnings' => []]);
})->with([
    'AMC 0' => ['0', false],
    'AMC 0.00' => ['0.00', false],
    'AMC missing' => [null, false],
    'No stock on hand ticked' => ['300', true],
]);

// Kyle, 2026-11-26: (Qty on Hand / AMC) x 30 to 2 decimal places, half up (was whole days, rounded down).
it('works out days on hand to 2 decimal places, null without stock', function (Closure $batches, ?string $expected) {
    expect(risk('500', $batches())['days_on_hand'])->toBe($expected);
})->with([
    '(200.50 / 500) x 30 = 12.03' => [fn () => [riskBatch(1, '120', 400), riskBatch(2, '80.5', 500)], '12.03'],
    '16.6662 rounds to 16.67' => [fn () => [riskBatch(1, '277.77', 400)], '16.67'],
    '0.9954 rounds half up to 1.00' => [fn () => [riskBatch(1, '16.59', 400)], '1.00'],
    'no batches' => [fn () => [], null],
    'zero stock' => [fn () => [riskBatch(1, '0', 400)], null],
]);

it('never flags a batch without an expiry date', function () {
    $undated = (new CaseStockExpiry)->forceFill(['id' => 9, 'quantity' => '100', 'expiry_date' => null]);

    // 110 units = 11 days on hand > 0 days to the earliest dated expiry.
    expect(risk('300', [$undated, riskBatch(1, '10', 0)]))
        ->toBe(['days_on_hand' => '11.00', 'expiry_warnings' => [1 => ['state' => 'at_risk', 'days_until_expiry' => 0, 'cover_days' => '11.00']]]);

    // Only undated stock: nothing to compare against.
    expect(risk('300', [$undated])['expiry_warnings'])->toBe([]);
});

// 2. Reference date --------------------------------------------------------------------

it('uses today for a draft and the submission date once submitted', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00', 'America/Port_of_Spain'));
    [$case, $vendor] = readyDraft();
    // 500 a month: 300 units = 18 days of cover; expires in 20 days.
    saveDraft($case, $vendor, ['requisition' => ['quantity_on_hand' => '300'], 'stock_expiries' => [['expiry_date' => '2026-12-07']]]);
    $case = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);

    expect(ExpiryRisk::for($case)['expiry_warnings'])->toBe([]);
    app(SubmitCase::class)->handle($case, $vendor);

    // Ten days later: as a draft it would be at risk (18 days of cover, 10 left); submitted, the date stays.
    $this->travelTo(CarbonImmutable::parse('2026-11-27 12:00', 'America/Port_of_Spain'));
    actAs($vendor); // the child rows are visible through the case
    $submitted = ProcurementCase::withoutGlobalScopes()->with(['requisition', 'stockExpiries'])->findOrFail($case->id);

    expect($submitted->stockExpiries)->toHaveCount(1)
        ->and(ExpiryRisk::referenceDate($submitted)->toDateString())->toBe('2026-11-17')
        ->and(ExpiryRisk::for($submitted)['expiry_warnings'])->toBe([]);

    $asDraft = ExpiryRisk::compute('500', '300', $submitted->stockExpiries, CarbonImmutable::parse('2026-11-27'));
    expect(array_column($asDraft['expiry_warnings'], 'state'))->toBe(['at_risk']);
});

it('uses the submission date in Trinidad time, not UTC', function () {
    // 02:00 UTC on 18 November is 22:00 on 17 November in Port of Spain.
    $case = (new ProcurementCase)->forceFill(['status' => 'SUBMITTED', 'submitted_at' => CarbonImmutable::parse('2026-11-18 02:00', 'UTC')]);

    expect(ExpiryRisk::referenceDate($case)->toDateString())->toBe('2026-11-17');
});

// 3. Where it shows: readiness warnings (never errors), the save result, both DTOs ------

it('reports the risk as readiness warnings and never blocks the submit', function () {
    [$case, $vendor] = readyDraft();
    $today = CarbonImmutable::now('America/Port_of_Spain');
    saveDraft($case, $vendor, ['requisition' => ['quantity_on_hand' => '350'], 'stock_expiries' => [
        ['expiry_date' => $today->subDay()->toDateString()],   // expired
        ['expiry_date' => $today->addDays(5)->toDateString()], // 21 days on hand in all, 5 left
    ]]);
    $case = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);

    $report = app(SubmissionReadiness::class)->evaluate($case);
    expect($report->errors)->toBe([])
        ->and($report->warnings)->toBe([
            ['key' => 'stock_expiries.0.expiry_date', 'message' => 'Stock batch 1 is past its expiry date.'],
            // Days on hand counts all stock (350 units at 500 a month = 21 days) against the earliest unexpired batch.
            ['key' => 'stock_expiries.1.expiry_date', 'message' => 'Stock batch 2 may expire before it is used: about 21.00 days of stock on hand, and this batch (the earliest to expire) expires in 5 days.'],
        ]);

    app(SubmitCase::class)->handle($case, $vendor);
    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('SUBMITTED');
});

it('returns days on hand and the warnings with the save result and on both pages', function () {
    [$case, $vendor] = readyDraft();
    $today = CarbonImmutable::now('America/Port_of_Spain');
    $payload = ['requisition' => ['quantity_on_hand' => '300'], 'stock_expiries' => [['expiry_date' => $today->addDays(5)->toDateString()]]];

    $saved = app(SaveRequisition::class)->handle($case, $vendor, RequisitionData::validate($payload, $case->procurementType));
    $batchId = (int) CaseStockExpiry::withoutGlobalScopes()->where('case_id', $case->id)->value('id');
    $expected = ['state' => 'at_risk', 'days_until_expiry' => 5, 'cover_days' => '18.00'];

    expect($saved->daysOnHand)->toBe('18.00')
        ->and($saved->expiryWarnings)->toBe([$batchId => $expected]);

    $this->actingAs($vendor)->from(route('requests.edit', $case->id))->put(route('requests.update', $case->id), $payload)
        ->assertSessionHas('saved', fn ($flash) => $flash['days_on_hand'] === '18.00'
            && $flash['expiry_warnings'] === [['batch_id' => (int) CaseStockExpiry::withoutGlobalScopes()->where('case_id', $case->id)->value('id'), ...$expected]]);

    $vendorView = pageProps($this->actingAs($vendor)->get(route('requests.show', $case->id))->assertOk())['case']['figures'];
    $batchId = (int) CaseStockExpiry::withoutGlobalScopes()->where('case_id', $case->id)->value('id');
    expect($vendorView['days_on_hand'])->toBe('18.00')
        ->and($vendorView['expiry_warnings'])->toBe([['batch_id' => $batchId, ...$expected]]);

    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);
    $staffView = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case->id))->assertOk())['case']['figures'];
    expect($staffView['days_on_hand'])->toBe('18.00')
        ->and($staffView['expiry_warnings'])->toBe([['batch_id' => $batchId, ...$expected]]);
});
