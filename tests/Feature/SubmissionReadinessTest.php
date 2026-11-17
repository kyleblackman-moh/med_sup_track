<?php

use App\Enums\TechnicalStatus;
use App\Models\CaseQuotation;
use App\Submissions\ReadinessReport;
use App\Submissions\SubmissionReadiness;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// Slice 8a: SubmissionReadiness. Drafts are built through CreateDraftCase and
// SaveRequisition, then evaluated. Read-only: evaluation writes nothing.
//
// Slice F1: these are the OLD types' rules (AD_HOC_PHARM, AD_HOC_NON_PHARM,
// TENDER_EXCESS), which still apply to their drafts. The inactive types' drafts
// come from legacyDraft() (draftOf). AD_HOC rules: AdHocReadinessTest.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    // Slice 8c: required documents count towards readiness; makeDocument() writes to this disk.
    Storage::fake('documents');
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Saves $payload (after $mutate) on a new draft of $code and evaluates it. */
function readinessOf(string $code, array $payload, ?Closure $mutate = null): ReadinessReport
{
    if ($mutate) {
        $payload = $mutate($payload);
    }

    [$case, $vendor] = draftOf($code);
    saveDraft($case, $vendor, $payload);
    // Slice 8c: the request-content tests assume the required documents are in place.
    attachRequiredDocuments($case);

    return app(SubmissionReadiness::class)->evaluate($case);
}

function errorKeys(ReadinessReport $report): array
{
    return array_column($report->errors, 'key');
}

function warningKeys(ReadinessReport $report): array
{
    return array_column($report->warnings, 'key');
}

it('reports the complete fixtures as ready with no warnings', function (string $code, Closure $payload) {
    $report = readinessOf($code, $payload());

    expect($report->errors)->toBe([])
        ->and($report->warnings)->toBe([])
        ->and($report->isReady())->toBeTrue()
        ->and($report->pending)->toBe([]); // slice 8c: documents are now evaluated
})->with([
    'AD_HOC_NON_PHARM' => ['AD_HOC_NON_PHARM', fn () => nonPharmPayload()],
    'AD_HOC_PHARM' => ['AD_HOC_PHARM', fn () => pharmPayload()],
    'TENDER_EXCESS' => ['TENDER_EXCESS', fn () => tenderExcessPayload()],
]);

it('reports each missing required item with its exact key', function (Closure $mutate, string $key) {
    $report = readinessOf('AD_HOC_NON_PHARM', nonPharmPayload(), $mutate);

    expect(errorKeys($report))->toContain($key)
        ->and($report->isReady())->toBeFalse();
})->with([
    'vendor_reference' => [function ($p) {
        $p['requisition']['vendor_reference'] = null;

        return $p;
    }, 'requisition.vendor_reference'],
    'request_date' => [function ($p) {
        $p['requisition']['request_date'] = null;

        return $p;
    }, 'requisition.request_date'],
    'need_classification' => [function ($p) {
        $p['requisition']['need_classification'] = null;

        return $p;
    }, 'requisition.need_classification'],
    'item_description' => [function ($p) {
        $p['requisition']['item_description'] = '   ';

        return $p;
    }, 'requisition.item_description'],
    'unit_of_measure' => [function ($p) {
        $p['requisition']['unit_of_measure'] = null;

        return $p;
    }, 'requisition.unit_of_measure'],
    'quantity_requested null' => [function ($p) {
        $p['requisition']['quantity_requested'] = null;

        return $p;
    }, 'requisition.quantity_requested'],
    'quantity_requested zero' => [function ($p) {
        $p['requisition']['quantity_requested'] = '0';

        return $p;
    }, 'requisition.quantity_requested'],
    'consumption zero' => [function ($p) {
        $p['requisition']['average_monthly_consumption'] = '0';

        return $p;
    }, 'requisition.average_monthly_consumption'],
    'consumption null' => [function ($p) {
        $p['requisition']['average_monthly_consumption'] = null;

        return $p;
    }, 'requisition.average_monthly_consumption'],
    'central stock null' => [function ($p) {
        $p['requisition']['central_stores_stock'] = null;

        return $p;
    }, 'requisition.central_stores_stock'],
    'rha stock null' => [function ($p) {
        $p['requisition']['rha_stock'] = null;

        return $p;
    }, 'requisition.rha_stock'],
    'rfq_date' => [function ($p) {
        $p['requisition']['rfq_date'] = null;

        return $p;
    }, 'requisition.rfq_date'],
    'rfq_audience' => [function ($p) {
        $p['requisition']['rfq_audience'] = null;

        return $p;
    }, 'requisition.rfq_audience'],
    'tender_outcome' => [function ($p) {
        $p['requisition']['tender_outcome'] = null;

        return $p;
    }, 'requisition.tender_outcome'],
    'no quotations' => [function ($p) {
        $p['quotations'] = [];

        return $p;
    }, 'quotations'],
    'lead time' => [function ($p) {
        $p['quotations'][2]['lead_time_days'] = null;

        return $p;
    }, 'quotations.2.lead_time_days'],
    'no recommendation' => [function ($p) {
        $p['quotations'][2]['is_recommended'] = false;

        return $p;
    }, 'quotations.recommended'],
    'recommended zero' => [function ($p) {
        $p['quotations'][2]['recommended_quantity'] = '0';

        return $p;
    }, 'quotations.recommended'],
    'over offered' => [function ($p) {
        $p['quotations'][2]['recommended_quantity'] = '3000.01';

        return $p;
    }, 'quotations.2.recommended_quantity'],
    'below minimum' => [function ($p) {
        $p['quotations'][2]['minimum_order_quantity'] = '2900';

        return $p;
    }, 'quotations.2.recommended_quantity'],
]);

it('requires a DIN for PHARM types only', function () {
    $pharm = readinessOf('AD_HOC_PHARM', pharmPayload(), function ($p) {
        $p['requisition']['din'] = null;

        return $p;
    });

    expect(errorKeys($pharm))->toBe(['requisition.din'])
        ->and(errorKeys(readinessOf('AD_HOC_NON_PHARM', nonPharmPayload())))->toBe([]);
});

it('does not require rfq or tender_outcome where the type does not', function () {
    expect(errorKeys(readinessOf('TENDER_EXCESS', tenderExcessPayload())))->toBe([])
        ->and(errorKeys(readinessOf('AD_HOC_PHARM', pharmPayload())))->toBe([]);
});

it('requires tender awards and purchase orders for TENDER_EXCESS', function (string $section) {
    $report = readinessOf('TENDER_EXCESS', tenderExcessPayload(), function ($p) use ($section) {
        $p[$section] = [];

        return $p;
    });

    expect(errorKeys($report))->toBe([$section]);
})->with(['tender_awards', 'purchase_orders']);

it('compares quantities exactly', function (string $recommended, bool $ok) {
    $report = readinessOf('AD_HOC_NON_PHARM', nonPharmPayload(), function ($p) use ($recommended) {
        $p['quotations'][2]['quantity_offered'] = '2800';
        $p['quotations'][2]['recommended_quantity'] = $recommended;
        $p['requisition']['quantity_requested'] = $recommended;

        return $p;
    });

    expect(in_array('quotations.2.recommended_quantity', errorKeys($report), true))->toBe(! $ok);
})->with([
    'equal to offered' => ['2800', true],
    '0.01 over' => ['2800.01', false],
    '0.01 under' => ['2799.99', true],
]);

it('accepts a recommended quantity equal to the minimum order quantity', function () {
    $report = readinessOf('AD_HOC_NON_PHARM', nonPharmPayload(), function ($p) {
        $p['quotations'][2]['minimum_order_quantity'] = '2800';

        return $p;
    });

    expect($report->isReady())->toBeTrue();
});

it('raises each warning without blocking submission', function (string $code, Closure $payload, Closure $mutate, string $key) {
    $report = readinessOf($code, $payload(), $mutate);

    expect(warningKeys($report))->toContain($key)
        ->and($report->isReady())->toBeTrue();
})->with([
    'purpose' => ['AD_HOC_NON_PHARM', fn () => nonPharmPayload(), function ($p) {
        $p['requisition']['purpose'] = null;

        return $p;
    }, 'requisition.purpose'],
    'urgency' => ['AD_HOC_NON_PHARM', fn () => nonPharmPayload(), function ($p) {
        $p['requisition']['urgency_explanation'] = '';

        return $p;
    }, 'requisition.urgency_explanation'],
    'tender_reference' => ['AD_HOC_NON_PHARM', fn () => nonPharmPayload(), function ($p) {
        $p['requisition']['tender_reference'] = null;

        return $p;
    }, 'requisition.tender_reference'],
    'stock_verified_on' => ['AD_HOC_NON_PHARM', fn () => nonPharmPayload(), function ($p) {
        $p['requisition']['stock_verified_on'] = null;

        return $p;
    }, 'requisition.stock_verified_on'],
    'total differs' => ['AD_HOC_NON_PHARM', fn () => nonPharmPayload(), function ($p) {
        $p['requisition']['quantity_requested'] = '3000';

        return $p;
    }, 'quotations.recommended_total'],
    'expired price' => ['AD_HOC_NON_PHARM', fn () => nonPharmPayload(), function ($p) {
        $p['quotations'][4]['price_valid_until'] = now()->subDays(2)->toDateString();

        return $p;
    }, 'quotations.4.price_valid_until'],
    'pharm stock without expiries' => ['AD_HOC_PHARM', fn () => pharmPayload(), function ($p) {
        $p['requisition']['rha_stock'] = '150';

        return $p;
    }, 'stock_expiries'],
]);

it('does not warn about expiries for PHARM stock when expiry rows exist', function () {
    $report = readinessOf('AD_HOC_PHARM', pharmPayload(), function ($p) {
        $p['requisition']['rha_stock'] = '150';
        $p['stock_expiries'] = [['quantity' => '150', 'expiry_date' => '2027-06-30']];

        return $p;
    });

    expect(warningKeys($report))->not->toContain('stock_expiries');
});

it('warns (informational) about a staff-set technical status', function () {
    [$case, $vendor] = draftOf('AD_HOC_NON_PHARM');
    saveDraft($case, $vendor, nonPharmPayload());
    attachRequiredDocuments($case);
    CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->where('sort_order', 1)->firstOrFail()
        ->forceFill(['technical_status' => TechnicalStatus::TECHNICALLY_REJECTED])->save();

    $report = app(SubmissionReadiness::class)->evaluate($case);

    expect(warningKeys($report))->toBe(['quotations.1.technical_status'])
        ->and($report->isReady())->toBeTrue();
});

it('gives every error and warning a key and a message', function () {
    $report = readinessOf('AD_HOC_PHARM', ['requisition' => ['rha_stock' => '5']]);

    foreach ([...$report->errors, ...$report->warnings] as $item) {
        expect(array_keys($item))->toBe(['key', 'message'])
            ->and($item['message'])->toBeString()->not->toBe('');
    }
    expect($report->isReady())->toBeFalse();
});

it('writes nothing while evaluating', function () {
    [$case, $vendor] = draftOf('AD_HOC_NON_PHARM');
    saveDraft($case, $vendor, nonPharmPayload());
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = strtolower(ltrim($q->sql));
    });

    app(SubmissionReadiness::class)->evaluate($case);

    expect(collect($queries)->reject(fn ($sql) => str_starts_with($sql, 'select'))->all())->toBe([]);
});
