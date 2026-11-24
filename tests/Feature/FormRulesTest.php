<?php

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use App\Submissions\Exceptions\SubmissionNotReady;
use App\Submissions\RequestSections;
use App\Submissions\SubmissionReadiness;
use App\Submissions\SubmitCase;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-23: the request form's quantity, order, stock and price-field
// rules. Each is a readiness error (blocks submit; the form shows it inline)
// while draft saves still accept the data. "Today" is the Port of Spain date.
// DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
    Queue::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    CarbonImmutable::setTestNow();
});

/** A saved draft of $code from the complete payload after $mutate. */
function frDraft(string $code = 'AD_HOC_TENDER_UNABLE', ?Closure $mutate = null): array
{
    [$case, $vendor] = newDraft($code);
    $payload = adHocPayload();
    if ($code === 'TENDER_VARIATION') {
        $payload['previous_request_in_cycle'] = true;
        $payload['variation_rows'] = [variationEntry()];
    }
    saveDraft($case, $vendor, $mutate ? $mutate($payload) : $payload);

    return [ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor];
}

/** Saves one order row against the first award: the fixture's order with $overrides. */
function frOrder(ProcurementCase $case, $vendor, array $overrides): void
{
    $order = [...adHocOrdersFor($case)['purchase_orders'][0], ...$overrides];
    saveDraft($case, $vendor, ['purchase_orders' => [$order]]);
}

/** Readiness errors as key => message. */
function frErrors(ProcurementCase $case): array
{
    $case = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);

    return collect(app(SubmissionReadiness::class)->evaluate($case)->errors)->pluck('message', 'key')->all();
}

// --- A. Quantity limits (Quotation comparison) -----------------------------

it('refuses a quotation row whose quantity is above the quantity requested', function () {
    [$case] = frDraft(mutate: function ($p) {
        $p['quotations'][1]['quantity_offered'] = '2801';

        return $p;
    });

    expect(frErrors($case))->toHaveKey('quotations.1.quantity_offered', 'Quotation Row 2: Quantity cannot be more than the quantity requested (2,800).');
});

it('accepts a quotation row quantity equal to the quantity requested', function () {
    [$case] = frDraft(); // the fixture offers exactly 2800

    expect(frErrors($case))->not->toHaveKey('quotations.0.quantity_offered')->not->toHaveKey('quotations.1.quantity_offered');
});

// Kyle, 2026-11-24: Quantity to Purchase left the form; it is never checked now.
it('does not check the quantity to purchase', function () {
    [$case] = frDraft(mutate: function ($p) {
        $p['requisition']['quantity_to_purchase'] = '3000';

        return $p;
    });

    expect(frErrors($case))->not->toHaveKey('requisition.quantity_to_purchase');
});

it('lets awarded supplier quantities exceed the quantity requested (tender quantities)', function () {
    [$case] = frDraft(); // the fixture awards 3000 against 2800 requested

    expect(collect(array_keys(frErrors($case)))->filter(fn ($k) => str_starts_with($k, 'tender_awards')))->toBeEmpty();
});

it('still saves a draft with a quantity above the quantity requested', function () {
    [$case] = frDraft(mutate: function ($p) {
        $p['quotations'][1]['quantity_offered'] = '9999';

        return $p;
    });

    expect(DB::table('case_quotations')->where('case_id', $case->id)->where('quantity_offered', '9999')->exists())->toBeTrue();
});

// --- B. Existing approved arrangement --------------------------------------

it('refuses a date ordered after today and accepts today', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 15:00', 'UTC'));
    [$case, $vendor] = frDraft();

    frOrder($case, $vendor, ['ordered_on' => '2026-11-24', 'delivery_state' => 'NOT_DELIVERED', 'quantity_delivered' => '0', 'delivery_date' => null]);
    expect(frErrors($case))->toHaveKey('purchase_orders.0.ordered_on', 'Order Row 1: Date ordered must be today or earlier.');

    frOrder($case, $vendor, ['ordered_on' => '2026-11-23', 'delivery_state' => 'NOT_DELIVERED', 'quantity_delivered' => '0', 'delivery_date' => null]);
    expect(frErrors($case))->not->toHaveKey('purchase_orders.0.ordered_on');
});

it('uses the Port of Spain date for today, at 23:30 UTC and just after midnight UTC', function () {
    [$case, $vendor] = frDraft();

    // 23:30 UTC = 19:30 in Port of Spain, the same date: the 24th is still the future.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 23:30', 'UTC'));
    frOrder($case, $vendor, ['ordered_on' => '2026-11-24', 'delivery_state' => 'NOT_DELIVERED', 'quantity_delivered' => '0', 'delivery_date' => null]);
    expect(frErrors($case))->toHaveKey('purchase_orders.0.ordered_on');

    // 02:00 UTC on the 24th = 22:00 on the 23rd in Port of Spain: the UTC date would wrongly allow the 24th.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 02:00', 'UTC'));
    expect(frErrors($case))->toHaveKey('purchase_orders.0.ordered_on');

    // 04:30 UTC on the 24th = 00:30 on the 24th in Port of Spain: now it is today.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 04:30', 'UTC'));
    expect(frErrors($case))->not->toHaveKey('purchase_orders.0.ordered_on');
});

it('refuses a quantity delivered above the quantity ordered', function () {
    [$case, $vendor] = frDraft();

    frOrder($case, $vendor, ['quantity_ordered' => '3000', 'quantity_delivered' => '3001']);
    expect(frErrors($case))->toHaveKey('purchase_orders.0.quantity_delivered', 'Order Row 1: Quantity delivered cannot be more than quantity ordered.');

    frOrder($case, $vendor, ['quantity_ordered' => '3000', 'quantity_delivered' => '3000', 'delivery_state' => 'FULLY_DELIVERED']);
    expect(frErrors($case))->not->toHaveKey('purchase_orders.0.quantity_delivered');
});

it('requires the delivery status on an entered order row', function () {
    [$case, $vendor] = frDraft();
    frOrder($case, $vendor, ['delivery_state' => null]);

    expect(frErrors($case))->toHaveKey('purchase_orders.0.delivery_state', 'Order Row 1: Delivery Status is required.');
});

it('requires the delivery date unless the status is Not delivered', function () {
    [$case, $vendor] = frDraft();

    frOrder($case, $vendor, ['delivery_state' => 'PARTIALLY_DELIVERED', 'delivery_date' => null]);
    expect(frErrors($case))->toHaveKey('purchase_orders.0.delivery_date', 'Order Row 1: Delivery Date is required.');

    frOrder($case, $vendor, ['delivery_state' => 'NOT_DELIVERED', 'quantity_delivered' => '0', 'delivery_date' => null]);
    expect(frErrors($case))->not->toHaveKey('purchase_orders.0.delivery_date');
});

it('refuses a delivery date after today (Port of Spain) and accepts today', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 02:00', 'UTC')); // 22:00 on the 23rd locally
    [$case, $vendor] = frDraft();

    frOrder($case, $vendor, ['delivery_date' => '2026-11-24']);
    expect(frErrors($case))->toHaveKey('purchase_orders.0.delivery_date', 'Order Row 1: Delivery date must be today or earlier.');

    frOrder($case, $vendor, ['delivery_date' => '2026-11-23']);
    expect(frErrors($case))->not->toHaveKey('purchase_orders.0.delivery_date');
});

it('refuses a delivery date before the date ordered and accepts the same day', function () {
    [$case, $vendor] = frDraft();

    frOrder($case, $vendor, ['ordered_on' => '2026-06-01', 'delivery_date' => '2026-05-31']);
    expect(frErrors($case))->toHaveKey('purchase_orders.0.delivery_date', 'Order Row 1: Delivery date cannot be before the date ordered.');

    frOrder($case, $vendor, ['ordered_on' => '2026-06-01', 'delivery_date' => '2026-06-01']);
    expect(frErrors($case))->not->toHaveKey('purchase_orders.0.delivery_date');
});

it('checks the delivery status against the quantities', function (string $state, string $delivered, ?string $message) {
    [$case, $vendor] = frDraft();
    frOrder($case, $vendor, [
        'quantity_ordered' => '3000',
        'quantity_delivered' => $delivered,
        'delivery_state' => $state,
        'delivery_date' => $state === 'NOT_DELIVERED' ? null : '2026-06-15',
    ]);

    $errors = frErrors($case);
    $message === null
        ? expect($errors)->not->toHaveKey('purchase_orders.0.delivery_state')
        : expect($errors)->toHaveKey('purchase_orders.0.delivery_state', 'Order Row 1: '.$message);
})->with([
    'fully, equal' => ['FULLY_DELIVERED', '3000', null],
    'fully, less' => ['FULLY_DELIVERED', '2999', RequestSections::FULLY_DELIVERED_MISMATCH],
    'partially, between' => ['PARTIALLY_DELIVERED', '1200', null],
    'partially, zero' => ['PARTIALLY_DELIVERED', '0', RequestSections::PARTIALLY_DELIVERED_MISMATCH],
    'partially, all' => ['PARTIALLY_DELIVERED', '3000', RequestSections::PARTIALLY_DELIVERED_MISMATCH],
    'not, zero' => ['NOT_DELIVERED', '0', null],
    'not, some' => ['NOT_DELIVERED', '5', RequestSections::NOT_DELIVERED_MISMATCH],
]);

it('no longer accepts the old delivery date and free-text status on the request form', function () {
    [$case, $vendor] = frDraft();

    expect(fn () => frOrder($case, $vendor, ['delivery_eta' => '2026-07-01', 'delivery_status' => 'Delivered']))
        ->toThrow(ValidationException::class);
});

// --- C. Stock ---------------------------------------------------------------

it('sets no stock on hand from the batch total, never from the form', function (array $batches, bool $none) {
    [$case] = frDraft(mutate: function ($p) use ($batches) {
        $p['stock_expiries'] = $batches;

        return $p;
    });

    expect((bool) CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('no_stock_on_hand'))->toBe($none)
        ->and(collect(array_keys(frErrors($case)))->filter(fn ($k) => str_starts_with($k, 'stock_expiries')))->toBeEmpty();
})->with([
    'no batches' => [[], true],
    'batches of 0' => [[['quantity' => '0', 'expiry_date' => null]], true],
    'stock on hand' => [[['quantity' => '10', 'expiry_date' => '2027-06-30']], false],
]);

it('is not "no stock on hand" until the average monthly consumption is entered', function () {
    [$case, $vendor] = newDraft();
    actAs($vendor); // the requisition relation is scoped to the viewer
    saveDraft($case, $vendor, ['requisition' => ['stock_location' => 'Central stores'], 'stock_expiries' => []]);

    $flag = fn () => (bool) CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('no_stock_on_hand');
    $total = fn () => ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)->batchStockOnHand();

    expect($flag())->toBeFalse()->and($total())->toBeNull(); // the form shows "—"

    saveDraft($case, $vendor, ['requisition' => ['average_monthly_consumption' => '0']]);
    expect($flag())->toBeTrue()->and($total())->toBe('0.00');

    saveDraft($case, $vendor, ['requisition' => ['average_monthly_consumption' => null]]);
    expect($flag())->toBeFalse()->and($total())->toBeNull();
});

it('refuses the old No stock on hand tick from the form', function () {
    [$case, $vendor] = newDraft();

    expect(fn () => saveDraft($case, $vendor, ['requisition' => ['no_stock_on_hand' => true]]))
        ->toThrow(ValidationException::class);
});

it('needs an expiry date on a batch with a quantity, and a quantity on every batch', function () {
    [$case] = frDraft(mutate: function ($p) {
        $p['stock_expiries'] = [['quantity' => '10', 'expiry_date' => null], ['quantity' => null, 'expiry_date' => '2027-06-30']];

        return $p;
    });

    expect(frErrors($case))
        ->toHaveKey('stock_expiries.0.expiry_date', 'Batch 1: Expiry Date is required.')
        ->toHaveKey('stock_expiries.1.quantity', 'Batch 2: Quantity is required.');
});

it('still requires the average monthly consumption', function () {
    [$case] = frDraft(mutate: function ($p) {
        $p['requisition']['average_monthly_consumption'] = null;
        $p['stock_expiries'] = [];

        return $p;
    });

    expect(frErrors($case))->toHaveKey('requisition.average_monthly_consumption');
});

it('corrects a stale no stock flag at submit', function () {
    [$case, $vendor] = readyDraft();
    DB::table('case_requisitions')->where('case_id', $case->id)->update(['no_stock_on_hand' => true]); // batches total 200.5

    app(SubmitCase::class)->handle($case, $vendor);

    expect((bool) CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('no_stock_on_hand'))->toBeFalse();
});

// --- E. Tender price / previous price ---------------------------------------

it('shows the tender and previous price for Tender Excess only', function (string $code, bool $shown) {
    $fields = RequestSections::requiredFields($code)['quotation_comparison'];

    expect(array_key_exists('tender_price', $fields))->toBe($shown)
        ->and(array_key_exists('previous_price', $fields))->toBe($shown)
        ->and(in_array('tender_price', RequestSections::hiddenFields($code), true))->toBe(! $shown);
})->with([
    'AD_HOC_EMERGENCY' => ['AD_HOC_EMERGENCY', false],
    'AD_HOC_TENDER_UNABLE' => ['AD_HOC_TENDER_UNABLE', false],
    'AD_HOC_NOT_AWARDED' => ['AD_HOC_NOT_AWARDED', false],
    'TENDER_EXCESS' => ['TENDER_EXCESS', true],
    'TENDER_VARIATION' => ['TENDER_VARIATION', false],
]);

it('keeps hidden prices in the draft and clears them at submit, auditing names only', function (string $code, bool $kept) {
    [$case, $vendor] = readyDraft($code);
    $prices = fn () => CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->first(['tender_price', 'previous_price']);

    expect($prices()->tender_price)->not->toBeNull(); // in the draft whatever the type

    app(SubmitCase::class)->handle($case, $vendor);

    $after = $prices();
    expect($after->tender_price !== null)->toBe($kept)->and($after->previous_price !== null)->toBe($kept);

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseDraftFieldsDiscarded->value)->first();
    if ($kept) {
        expect($audit)->toBeNull();
    } else {
        expect(json_decode($audit->metadata, true))->toBe(['reason' => 'field_hidden', 'fields' => ['tender_price', 'previous_price']])
            ->and((string) $audit->metadata)->not->toContain('1.25');
    }
})->with([
    'AD_HOC_EMERGENCY' => ['AD_HOC_EMERGENCY', false],
    'AD_HOC_TENDER_UNABLE' => ['AD_HOC_TENDER_UNABLE', false],
    'AD_HOC_NOT_AWARDED' => ['AD_HOC_NOT_AWARDED', false],
    'TENDER_EXCESS' => ['TENDER_EXCESS', true],
    'TENDER_VARIATION' => ['TENDER_VARIATION', false],
]);

// --- Readiness ----------------------------------------------------------------

it('refuses to submit while any of these rules fails, and submits once it is fixed', function () {
    [$case, $vendor] = readyDraft();
    saveDraft($case, $vendor, ['requisition' => ['tender_cycle_end' => CarbonImmutable::now('America/Port_of_Spain')->addDay()->toDateString()]]);

    expect(fn () => app(SubmitCase::class)->handle($case, $vendor))->toThrow(SubmissionNotReady::class)
        ->and(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)->status)->toBe(CaseStatus::DRAFT);

    saveDraft($case, $vendor, ['requisition' => ['tender_cycle_end' => '2026-10-31']]);
    app(SubmitCase::class)->handle($case, $vendor);

    expect(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)->status)->toBe(CaseStatus::SUBMITTED);
});
