<?php

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\DeliveryState;
use App\Models\CasePurchaseOrder;
use App\Models\CaseRequisition;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
use App\Submissions\Exceptions\SubmissionNotReady;
use App\Submissions\ProcurementTypeProfile;
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

// Kyle, 2026-11-24: Qty to Purchase left the form; it is never checked now.
it('does not check the quantity to purchase', function () {
    [$case] = frDraft(mutate: function ($p) {
        $p['requisition']['quantity_to_purchase'] = '3000';

        return $p;
    });

    expect(frErrors($case))->not->toHaveKey('requisition.quantity_to_purchase');
});

// Kyle, 2026-11-24: no supplier's quantity awarded may be above the quantity
// requested (replaces the 2026-11-23 rule that award quantities may exceed it).
it('refuses an awarded supplier quantity above the quantity requested', function () {
    [$case] = frDraft(mutate: function ($p) {
        $p['tender_awards'][1]['quantity_awarded'] = '2800.01';

        return $p;
    });

    expect(frErrors($case))->toHaveKey('tender_awards.1.quantity_awarded', 'Supplier Row 2: Quantity cannot be more than the quantity requested (2,800).')
        ->and(frErrors($case))->not->toHaveKey('tender_awards.0.quantity_awarded');
});

it('blocks submit while an awarded supplier quantity is above the quantity requested', function () {
    [$case, $vendor] = readyDraft();
    $awards = fn (string $first) => ['tender_awards' => CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)
        ->orderBy('sort_order')->orderBy('id')->get()
        ->map(fn (CaseTenderAward $a, int $i) => [
            'id' => $a->id, 'supplier_name' => $a->supplier_name, 'unit_cost' => $a->unit_cost,
            'quantity_awarded' => $i === 0 ? $first : $a->quantity_awarded,
        ])->all()];

    saveDraft($case, $vendor, $awards('3000'));
    expect(frErrors($case))->toHaveKey('tender_awards.0.quantity_awarded')
        ->and(fn () => app(SubmitCase::class)->handle($case, $vendor))->toThrow(SubmissionNotReady::class)
        ->and(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)->status)->toBe(CaseStatus::DRAFT);

    saveDraft($case, $vendor, $awards('2100'));
    app(SubmitCase::class)->handle($case, $vendor);

    expect(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)->status)->toBe(CaseStatus::SUBMITTED);
});

it('accepts awarded supplier quantities up to the quantity requested, each row on its own', function () {
    [$case] = frDraft(mutate: function ($p) {
        $p['tender_awards'][0]['quantity_awarded'] = '2800'; // equal; with row 2 the total is above 2800

        return $p;
    });

    expect(collect(array_keys(frErrors($case)))->filter(fn ($k) => str_starts_with($k, 'tender_awards')))->toBeEmpty();
});

// Kyle, 2026-11-24: % awarded is a typed, optional field (0 to 100, up to 2 decimals).
it('saves the typed % awarded as entered, and never requires it', function (?string $value, ?string $stored) {
    [$case] = frDraft(mutate: function ($p) use ($value) {
        $p['tender_awards'][0]['percentage_awarded'] = $value;

        return $p;
    });

    $saved = DB::table('case_tender_awards')->where('case_id', $case->id)->orderBy('sort_order')->orderBy('id')->value('percentage_awarded');

    // SQL Server returns 0 as ".00": compared as numbers.
    expect($saved === null ? null : bcadd((string) $saved, '0', 2))->toBe($stored)
        ->and(collect(array_keys(frErrors($case)))->filter(fn ($k) => str_contains($k, 'percentage_awarded')))->toBeEmpty();
})->with([
    'blank' => [null, null],
    'whole' => ['60', '60.00'],
    'two decimals' => ['33.33', '33.33'],
    'zero' => ['0', '0.00'],
]);

it('refuses a % awarded outside 0 to 100 or with more than 2 decimals', function (string $value) {
    expect(fn () => frDraft(mutate: function ($p) use ($value) {
        $p['tender_awards'][0]['percentage_awarded'] = $value;

        return $p;
    }))->toThrow(ValidationException::class, '% Awarded');
})->with(['above 100' => '100.01', 'three decimals' => '12.345', 'negative' => '-1']);

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
    [$case, $vendor] = frDraft('TENDER_VARIATION');

    frOrder($case, $vendor, ['ordered_on' => '2026-11-24', 'delivery_state' => 'NOT_DELIVERED', 'quantity_delivered' => '0', 'delivery_date' => null]);
    expect(frErrors($case))->toHaveKey('purchase_orders.0.ordered_on', 'Order Row 1: Date ordered must be today or earlier.');

    frOrder($case, $vendor, ['ordered_on' => '2026-11-23', 'delivery_state' => 'NOT_DELIVERED', 'quantity_delivered' => '0', 'delivery_date' => null]);
    expect(frErrors($case))->not->toHaveKey('purchase_orders.0.ordered_on');
});

it('uses the Port of Spain date for today, at 23:30 UTC and just after midnight UTC', function () {
    [$case, $vendor] = frDraft('TENDER_VARIATION');

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
    [$case, $vendor] = frDraft('TENDER_VARIATION');

    frOrder($case, $vendor, ['quantity_ordered' => '3000', 'quantity_delivered' => '3001']);
    expect(frErrors($case))->toHaveKey('purchase_orders.0.quantity_delivered', 'Order Row 1: Quantity delivered cannot be more than quantity ordered.');

    frOrder($case, $vendor, ['quantity_ordered' => '3000', 'quantity_delivered' => '3000', 'delivery_state' => 'FULLY_DELIVERED']);
    expect(frErrors($case))->not->toHaveKey('purchase_orders.0.quantity_delivered');
});

it('requires the delivery status on an entered order row', function () {
    [$case, $vendor] = frDraft('TENDER_VARIATION');
    frOrder($case, $vendor, ['delivery_state' => null]);

    expect(frErrors($case))->toHaveKey('purchase_orders.0.delivery_state', 'Order Row 1: Delivery Status is required.');
});

it('requires the delivery date unless the status is Not delivered', function () {
    [$case, $vendor] = frDraft('TENDER_VARIATION');

    frOrder($case, $vendor, ['delivery_state' => 'PARTIALLY_DELIVERED', 'delivery_date' => null]);
    expect(frErrors($case))->toHaveKey('purchase_orders.0.delivery_date', 'Order Row 1: Delivery Date is required.');

    frOrder($case, $vendor, ['delivery_state' => 'NOT_DELIVERED', 'quantity_delivered' => '0', 'delivery_date' => null]);
    expect(frErrors($case))->not->toHaveKey('purchase_orders.0.delivery_date');
});

it('asks for the description of an Other delivery status, and no delivery date or quantity check', function () {
    [$case, $vendor] = frDraft('TENDER_VARIATION');

    // Kyle, 2026-11-25: Other is described in words (the older delivery_status column).
    frOrder($case, $vendor, ['delivery_state' => 'OTHER', 'delivery_status' => '', 'delivery_date' => null, 'quantity_ordered' => '3000', 'quantity_delivered' => '0']);
    expect(frErrors($case))->toHaveKey('purchase_orders.0.delivery_status', 'Order Row 1: Other Delivery Status is required.')
        ->not->toHaveKey('purchase_orders.0.delivery_date')
        ->not->toHaveKey('purchase_orders.0.delivery_state');

    frOrder($case, $vendor, ['delivery_state' => 'OTHER', 'delivery_status' => 'Variation of tender in progress', 'delivery_date' => null]);
    expect(frErrors($case))->not->toHaveKey('purchase_orders.0.delivery_status')
        ->and(CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->value('delivery_status'))->toBe('Variation of tender in progress');
});

it('lists the delivery statuses in the agreed order', function () {
    expect(array_map(fn (DeliveryState $s) => $s->label(), DeliveryState::cases()))
        ->toBe(['Partial Delivery', 'Delivered', 'Not Delivered', 'Other']);
});

it('refuses a delivery date after today (Port of Spain) and accepts today', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 02:00', 'UTC')); // 22:00 on the 23rd locally
    [$case, $vendor] = frDraft('TENDER_VARIATION');

    frOrder($case, $vendor, ['delivery_date' => '2026-11-24']);
    expect(frErrors($case))->toHaveKey('purchase_orders.0.delivery_date', 'Order Row 1: Delivery date must be today or earlier.');

    frOrder($case, $vendor, ['delivery_date' => '2026-11-23']);
    expect(frErrors($case))->not->toHaveKey('purchase_orders.0.delivery_date');
});

it('refuses a delivery date before the date ordered and accepts the same day', function () {
    [$case, $vendor] = frDraft('TENDER_VARIATION');

    frOrder($case, $vendor, ['ordered_on' => '2026-06-01', 'delivery_date' => '2026-05-31']);
    expect(frErrors($case))->toHaveKey('purchase_orders.0.delivery_date', 'Order Row 1: Delivery date cannot be before the date ordered.');

    frOrder($case, $vendor, ['ordered_on' => '2026-06-01', 'delivery_date' => '2026-06-01']);
    expect(frErrors($case))->not->toHaveKey('purchase_orders.0.delivery_date');
});

it('checks the delivery status against the quantities', function (string $state, string $delivered, ?string $message) {
    [$case, $vendor] = frDraft('TENDER_VARIATION');
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
    [$case, $vendor] = frDraft('TENDER_VARIATION');

    expect(fn () => frOrder($case, $vendor, ['delivery_eta' => '2026-07-01', 'delivery_status' => 'Delivered']))
        ->toThrow(ValidationException::class);
});

// Kyle, 2026-11-25: Existing Approved Arrangement (and its tender issues text) shows for every type again.
it('shows Existing Approved Arrangement for every type', function (string $code) {
    expect(in_array('existing_arrangement', array_column(RequestSections::visible($code, previousRequest: true), 'key'), true))->toBeTrue()
        ->and(RequestSections::hiddenFields($code))->not->toContain('tender_issue_explanation');
})->with([...ProcurementTypeProfile::REQUEST_TYPES, 'NOT_SELECTED']);

it('checks orders on every type and keeps them at submit', function () {
    [$case, $vendor] = readyDraft('AD_HOC_TENDER_UNABLE');
    frOrder($case, $vendor, ['quantity_delivered' => '999999']);

    expect(frErrors($case))->toHaveKey('purchase_orders.0.quantity_delivered');

    frOrder($case, $vendor, ['quantity_delivered' => '100']);
    app(SubmitCase::class)->handle($case, $vendor);

    expect(DB::table('case_purchase_orders')->where('case_id', $case->id)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseDraftRowsDiscarded->value)->exists())->toBeFalse();
});

it('keeps and checks orders on a Tender Variation', function () {
    [$case, $vendor] = frDraft('TENDER_VARIATION');
    frOrder($case, $vendor, ['quantity_ordered' => '3000', 'quantity_delivered' => '3001']);

    expect(frErrors($case))->toHaveKey('purchase_orders.0.quantity_delivered');
});

// --- C. Stock ---------------------------------------------------------------

// Kyle, 2026-11-24: the quantity on hand is one typed quantity (no longer the batch
// total); "no stock on hand" shows when it is entered as 0.
it('sets no stock on hand from the typed quantity on hand, never from the form', function (?string $onHand, bool $none) {
    [$case] = frDraft(mutate: function ($p) use ($onHand) {
        $p['requisition']['quantity_on_hand'] = $onHand;

        return $p;
    });

    expect((bool) CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('no_stock_on_hand'))->toBe($none);
})->with([
    'not entered' => [null, false],
    'zero' => ['0', true],
    'stock on hand' => ['10', false],
]);

it('reads the quantity on hand from the typed field, not the batches', function () {
    [$case, $vendor] = newDraft();
    actAs($vendor); // the requisition relation is scoped to the viewer
    saveDraft($case, $vendor, ['requisition' => ['stock_location' => 'Central stores'], 'stock_expiries' => [['quantity' => '50', 'expiry_date' => '2027-06-30']]]);

    $onHand = fn () => ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)->quantityOnHand();
    expect($onHand())->toBeNull(); // the form shows "—"; the batch quantity is not read

    saveDraft($case, $vendor, ['requisition' => ['quantity_on_hand' => '120.5']]);
    expect($onHand())->toBe('120.50');
});

it('refuses the old No stock on hand tick from the form', function () {
    [$case, $vendor] = newDraft();

    expect(fn () => saveDraft($case, $vendor, ['requisition' => ['no_stock_on_hand' => true]]))
        ->toThrow(ValidationException::class);
});

it('needs an expiry date on every batch, and no batch quantity', function () {
    [$case] = frDraft(mutate: function ($p) {
        $p['stock_expiries'] = [['quantity' => '10', 'expiry_date' => null], ['expiry_date' => '2027-06-30']];

        return $p;
    });

    expect(frErrors($case))
        ->toHaveKey('stock_expiries.0.expiry_date', 'Batch 1: Expiry Date is required.')
        ->not->toHaveKey('stock_expiries.1.quantity');
});

it('leaves the quantity on hand optional for now', function () {
    [$case] = frDraft(mutate: function ($p) {
        $p['requisition']['quantity_on_hand'] = null;

        return $p;
    });

    expect(frErrors($case))->not->toHaveKey('requisition.quantity_on_hand');
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
    DB::table('case_requisitions')->where('case_id', $case->id)->update(['no_stock_on_hand' => true]); // quantity on hand 200.5

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

    // 2026-11-25: the tender issues text shows for every type again, so only the prices are cleared.
    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseDraftFieldsDiscarded->value)->first();
    if ($kept) {
        expect($audit)->toBeNull();

        return;
    }
    expect(json_decode($audit->metadata, true))->toBe(['reason' => 'field_hidden', 'fields' => ['tender_price', 'previous_price']])
        ->and((string) $audit->metadata)->not->toContain('1.25')->not->toContain('delivered part');
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
