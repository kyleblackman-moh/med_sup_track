<?php

use App\Http\Resources\Requests\RequestFormView;
use App\Models\CaseQuotation;
use App\Models\ProcurementCase;
use App\Submissions\SubmissionReadiness;
use App\Support\QuotationPrice;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-23: the Quotation comparison's Subtotal, VAT and Total Price,
// computed by the server and live by the form with the same rounding (half-up
// to 2 dp on Subtotal and on VAT; Total = their sum). DatabaseTransactions only.

/** quantity, unit price => subtotal, VAT, total at the configured 12.5%. */
const QP_CASES = [
    'three at 0.33' => ['3', '0.33', '0.99', '0.12', '1.11'],
    'seven at 19.99' => ['7', '19.99', '139.93', '17.49', '157.42'],
    'zero quantity' => ['0', '19.99', '0.00', '0.00', '0.00'],
    'zero price' => ['10', '0', '0.00', '0.00', '0.00'],
    'VAT exactly half a cent' => ['1', '0.04', '0.04', '0.01', '0.05'],
    'subtotal exactly half a cent' => ['2.5', '0.01', '0.03', '0.00', '0.03'],
    'four-place price' => ['1', '1.0050', '1.01', '0.13', '1.14'],
    'decimal quantity' => ['2800.75', '1.5975', '4474.20', '559.28', '5033.48'],
    'large values' => ['999999999999.99', '9999999999.9999', '9999999999999800000000.00', '1249999999999975000000.00', '11249999999999775000000.00'],
];

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

it('reads the VAT rate from config as a string', function () {
    expect(config('procurement.vat_rate'))->toBe('0.125')
        ->and(QuotationPrice::compute('1', '100', '0.2'))->toBe(['subtotal' => '100.00', 'vat' => '20.00', 'total' => '120.00']);
});

it('computes subtotal, VAT and total with half-up rounding', function (string $qty, string $price, string $subtotal, string $vat, string $total) {
    expect(QuotationPrice::compute($qty, $price))->toBe(['subtotal' => $subtotal, 'vat' => $vat, 'total' => $total]);
})->with(QP_CASES);

it('computes nothing until both quantity and unit price are entered', function () {
    expect(QuotationPrice::compute(null, '1.00'))->toBeNull()
        ->and(QuotationPrice::compute('3', null))->toBeNull();
});

it('gives the form the same figures as the server (the TypeScript helper, run with Node)', function () {
    if (Process::run(['node', '--version'])->failed()) {
        $this->markTestSkipped('Node is not available.');
    }

    $file = 'file:///'.str_replace('\\', '/', resource_path('js/lib/quotation-price.ts'));
    $inputs = json_encode(array_map(fn (array $c) => [$c[0], $c[1]], array_values(QP_CASES)));
    $script = "import { quotationPrice } from '{$file}'; console.log(JSON.stringify({$inputs}.map(([q, p]) => quotationPrice(q, p, '0.125'))));";

    $run = Process::run(['node', '--experimental-strip-types', '--no-warnings', '--input-type=module', '-e', $script]);
    expect($run->successful())->toBeTrue($run->errorOutput());

    $ui = json_decode(trim($run->output()), true);
    foreach (array_values(QP_CASES) as $i => [$qty, $price]) {
        expect($ui[$i])->toBe(QuotationPrice::compute($qty, $price));
    }
});

it('computes each saved row and the total cost from the recommended row', function () {
    [$case, $vendor] = newDraft();
    actAs($vendor); // the case's relations are scoped to the viewer
    saveDraft($case, $vendor, adHocPayload()); // recommended: 2800 at 1.5975

    $case = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
    $recommended = $case->quotations->firstWhere('is_recommended', true);

    expect($recommended)->toBeInstanceOf(CaseQuotation::class)
        ->and($recommended->subtotal())->toBe('4473.00')
        ->and($recommended->vatAmount())->toBe('559.13')
        ->and($recommended->totalPrice())->toBe('5032.13')
        ->and($case->totalCost())->toBe('5032.13');
});

it('sends the row figures, the total cost and the VAT rate to the form', function () {
    [$case, $vendor] = newDraft();
    actAs($vendor);
    saveDraft($case, $vendor, adHocPayload());
    $case = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);

    $display = RequestFormView::for($case, app(SubmissionReadiness::class)->evaluate($case))['display'];
    $rows = collect($display['rows']['quotations'])->keyBy('id');
    $recommended = $case->quotations->firstWhere('is_recommended', true);

    expect($display['vat_rate'])->toBe('0.125')
        ->and($display['total_cost'])->toBe('$5,032.13')
        ->and($rows[$recommended->id])->toBe(['id' => $recommended->id, 'subtotal' => '$4,473.00', 'vat' => '$559.13', 'total_price' => '$5,032.13']);
});
