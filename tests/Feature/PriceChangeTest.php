<?php

use App\Support\QuotationPrice;
use Illuminate\Support\Facades\Process;

// Kyle, 2026-11-25: the Tender Excess price comparison shows, live, how the
// recommended quotation's unit price compares with the tender and previous
// prices. The server (QuotationPrice::changePercent) and the form
// (priceChange in resources/js/lib/quotation-price.ts) use one rule:
// (quoted - reference) / reference x 100, half-up away from zero to 2 dp.

/** quoted, reference => change. */
const PC_CASES = [
    'higher' => ['389.0000', '345.7778', '12.50'],
    'lower' => ['97', '100', '-3.00'],
    'the same' => ['1.5975', '1.5975', '0.00'],
    'half up' => ['1.00005', '1', '0.01'],
    'half away from zero' => ['0.99995', '1', '-0.01'],
    'tiny drop rounds to zero' => ['0.99999', '1', '0.00'],
    'thirds' => ['2', '3', '-33.33'],
    'doubled' => ['20', '10', '100.00'],
    'large' => ['9999999999.9999', '0.0001', '9999999999999800.00'],
];

it('works out the change against a reference price', function (string $quoted, string $reference, string $change) {
    expect(QuotationPrice::changePercent($quoted, $reference))->toBe($change);
})->with(PC_CASES);

it('works out nothing without both prices or against a zero price', function () {
    expect(QuotationPrice::changePercent(null, '1'))->toBeNull()
        ->and(QuotationPrice::changePercent('1', null))->toBeNull()
        ->and(QuotationPrice::changePercent('1', '0'))->toBeNull()
        ->and(QuotationPrice::changePercent('1', 'abc'))->toBeNull();
});

it('gives the form the same change as the server (the TypeScript helper, run with Node)', function () {
    if (Process::run(['node', '--version'])->failed()) {
        $this->markTestSkipped('Node is not available.');
    }

    $file = 'file:///'.str_replace('\\', '/', resource_path('js/lib/quotation-price.ts'));
    $inputs = json_encode([...array_map(fn (array $c) => [$c[0], $c[1]], array_values(PC_CASES)), ['1', ''], ['', '1'], ['1', '0']]);
    $script = "import { priceChange } from '{$file}'; console.log(JSON.stringify({$inputs}.map(([q, r]) => priceChange(q, r))));";

    $run = Process::run(['node', '--experimental-strip-types', '--no-warnings', '--input-type=module', '-e', $script]);
    expect($run->successful())->toBeTrue($run->errorOutput());

    $ui = json_decode(trim($run->output()), true);
    foreach (array_values(PC_CASES) as $i => [$quoted, $reference, $change]) {
        expect($ui[$i])->toBe($change, "{$quoted} against {$reference}");
    }
    expect(array_slice($ui, -3))->toBe([null, null, null]);
});

it('adds up the % Awarded as the form shows it (the TypeScript helper, run with Node)', function () {
    if (Process::run(['node', '--version'])->failed()) {
        $this->markTestSkipped('Node is not available.');
    }

    $file = 'file:///'.str_replace('\\', '/', resource_path('js/lib/quotation-price.ts'));
    $script = "import { sumPercent } from '{$file}'; console.log(JSON.stringify([sumPercent(['60', '40']), sumPercent(['33.33', '33.33', '33.33']), sumPercent(['', 'abc']), sumPercent(['50', ''])]));";
    $run = Process::run(['node', '--experimental-strip-types', '--no-warnings', '--input-type=module', '-e', $script]);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and(json_decode(trim($run->output()), true))->toBe(['100.00', '99.99', null, '50.00']);
});
