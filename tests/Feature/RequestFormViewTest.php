<?php

use App\Http\Resources\Requests\RequestFormView;
use App\Models\ProcurementCase;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequestSections;
use App\Support\Display;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// Slice V2-2: the request form's "form" prop (RequestFormView): numbered
// sections with states and field-level required flags, display strings and the
// structured expiry warning. DatabaseTransactions only.

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

/** The "form" prop of the edit page for $case as $vendor. */
function formProp($test, ProcurementCase $case, $vendor): array
{
    return pageProps($test->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk())['form'];
}

function formSections(array $form): array
{
    return collect($form['sections'])->keyBy('key')->all();
}

// 1. Sections: order, numbering, visibility, state ------------------------------------

it('lists every section in form order, numbering only the visible ones', function (string $code) {
    [$case, $vendor] = newDraft($code);
    $form = formProp($this, $case, $vendor);

    $variation = $code === 'TENDER_VARIATION';
    expect(array_column($form['sections'], 'key'))->toBe(RequestSections::ORDER)
        ->and(array_column($form['sections'], 'number', 'key'))->toBe($variation
            ? ['item_tender' => 1, 'variation_details' => 2, 'awarded_supplier' => 3, 'existing_arrangement' => 4, 'stock' => 5, 'quotation_comparison' => 6, 'documents' => 7]
            : ['item_tender' => 1, 'variation_details' => null, 'awarded_supplier' => 2, 'existing_arrangement' => 3, 'stock' => 4, 'quotation_comparison' => 5, 'documents' => 6])
        ->and(array_column($form['sections'], 'visible', 'key'))->toBe([
            'item_tender' => true, 'variation_details' => $variation, 'awarded_supplier' => true, 'existing_arrangement' => true,
            'stock' => true, 'quotation_comparison' => true, 'documents' => true,
        ])
        ->and(array_column($form['sections'], 'label', 'key'))->toBe(RequestSections::LABELS)
        ->and($form['sections'][0]['label'])->toBe('Item and tender details');
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('gives an empty draft incomplete required sections and optional ones per type', function () {
    [$case, $vendor] = newDraft('TENDER_VARIATION');
    $sections = formSections(formProp($this, $case, $vendor));

    expect(collect($sections)->map(fn ($s) => $s['state'])->all())->toBe([
        'item_tender' => 'incomplete',
        'variation_details' => 'incomplete',
        'awarded_supplier' => 'incomplete',
        'existing_arrangement' => 'optional',
        'stock' => 'optional',
        'quotation_comparison' => 'optional',
        'documents' => 'optional', // a Tender Variation requires no file, and none is attached
    ])->and(collect($sections)->pluck('note')->filter()->all())->toBe([]);
});

it('marks the complete sections complete', function () {
    [$case, $vendor] = v2DraftForForm('AD_HOC_TENDER_UNABLE');
    $sections = formSections(formProp($this, $case, $vendor));

    expect(collect($sections)->where('visible', true)->map(fn ($s) => $s['state'])->all())->toBe([
        'item_tender' => 'complete',
        'awarded_supplier' => 'complete',
        'existing_arrangement' => 'complete', // optional, and it holds a valid order
        'stock' => 'complete',
        'quotation_comparison' => 'complete',
        'documents' => 'complete',
    ]);
});

it('blocks the type-dependent sections of a draft with no type chosen', function () {
    $vendor = vendorUser(vendorOrg());
    $this->actingAs($vendor)->post(route('requests.store'))->assertRedirect();
    $case = ProcurementCase::withoutGlobalScopes()->where('created_by', $vendor->id)->latest('created_at')->firstOrFail();

    $sections = formSections(formProp($this, $case, $vendor));

    foreach (RequestSections::TYPE_DEPENDENT as $key) {
        if ($sections[$key]['visible']) {
            expect($sections[$key]['state'])->toBe('blocked', $key)
                ->and($sections[$key]['note'])->toBe(RequestSections::REQUEST_TYPE_NEEDED);
        }
    }
    expect($sections['item_tender']['state'])->toBe('incomplete')
        ->and($sections['item_tender']['note'])->toBeNull()
        // Nothing type-dependent is optional until the type is chosen.
        ->and($sections['item_tender']['required']['tender_reference'])->toBeTrue()
        ->and($sections['stock']['required']['average_monthly_consumption'])->toBeTrue()
        ->and($sections['quotation_comparison']['required']['quantity_to_purchase'])->toBeTrue();
});

// 2. Required flags ---------------------------------------------------------------------

it('sends the field-level required flags of each type from the section rules', function (string $code) {
    [$case, $vendor] = newDraft($code);
    $sections = formSections(formProp($this, $case, $vendor));
    $r = V2_FORM_REQUIRES[$code];

    $item = $sections['item_tender']['required'];
    expect($item)->toBe([
        'item_description' => true, 'strength' => false, 'dosage_form' => false, 'din' => true,
        'quantity_requested' => true, 'unit_of_measure' => true, 'tender_reference' => $r['contract'],
        'tender_cycle_start' => true, 'tender_cycle_end' => true, 'clinical_note' => false,
    ])
        ->and(array_values(array_unique($sections['variation_details']['required'])))->toBe([$r['variation']])
        ->and($sections['stock']['required']['average_monthly_consumption'])->toBe($r['stock'])
        ->and($sections['stock']['required']['stock_location'])->toBeFalse()
        ->and($sections['stock']['required']['quantity_on_order'])->toBeFalse()
        ->and($sections['quotation_comparison']['required']['quantity_to_purchase'])->toBe($r['quotes'])
        ->and($sections['quotation_comparison']['required']['rfq_audience'])->toBeFalse()
        // A row that is entered must be complete, whatever the type.
        ->and($sections['awarded_supplier']['required'])->toBe([
            'tender_awards.supplier_name' => true, 'tender_awards.manufacturer' => false, 'tender_awards.brand' => false,
            'tender_awards.country_of_origin' => false, 'tender_awards.quantity_awarded' => true, 'tender_awards.unit_cost' => true,
        ])
        ->and($sections['existing_arrangement']['required']['purchase_orders.award_id'])->toBeTrue()
        ->and($sections['existing_arrangement']['required']['purchase_orders.ordered_on'])->toBeFalse()
        ->and($sections['existing_arrangement']['required']['tender_issue_explanation'])->toBeFalse()
        ->and($sections['quotation_comparison']['required']['quotations.unit_price'])->toBeTrue()
        ->and($sections['quotation_comparison']['required']['quotations.remarks'])->toBeFalse();
})->with(ProcurementTypeProfile::REQUEST_TYPES);

// 3. Display strings ---------------------------------------------------------------------

it('formats the computed values on the server', function () {
    [$case, $vendor] = v2DraftForForm('AD_HOC_TENDER_UNABLE');
    $display = formProp($this, $case, $vendor)['display'];

    $case->load(['tenderAwards', 'purchaseOrders', 'stockExpiries']);
    $awards = $case->tenderAwards->values();

    expect($display['stock_total'])->toBe('200.5')
        ->and($display['days_on_hand'])->toBe('12') // 200.5 x 30 / 500, rounded down
        ->and($display['awards_total'])->toBe('5,050.00')
        ->and($display['total_cost'])->toBe('4,473.00') // 1.5975 x 2800
        // V2-3: the "%" is added by the server: (1.5975 - 1.25) / 1.25 and (1.5975 - 1.50) / 1.50.
        ->and($display['price_change_vs_tender'])->toBe('27.80%')
        ->and($display['price_change_vs_previous'])->toBe('6.50%')
        ->and($display['rows']['tender_awards'])->toBe([
            ['id' => $awards[0]->id, 'percentage' => '75.00%', 'line_cost' => '3,750.00'],
            ['id' => $awards[1]->id, 'percentage' => '25.00%', 'line_cost' => '1,300.00'],
        ])
        ->and($display['rows']['purchase_orders'])->toBe([['id' => $case->purchaseOrders->first()->id, 'quantity_outstanding' => '1,800']])
        ->and(array_column($display['rows']['stock_expiries'], 'expiry_date'))
        ->toBe($case->stockExpiries->map(fn ($b) => $b->expiry_date->format('j M Y'))->values()->all());
});

it('shows a dash for values that cannot be worked out yet', function () {
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY');
    $display = formProp($this, $case, $vendor)['display'];

    expect($display['stock_total'])->toBe(Display::EMPTY)
        ->and($display['days_on_hand'])->toBe(Display::EMPTY)
        ->and($display['awards_total'])->toBe(Display::EMPTY)
        ->and($display['total_cost'])->toBe(Display::EMPTY)
        ->and($display['rows'])->toBe(['tender_awards' => [], 'purchase_orders' => [], 'stock_expiries' => []]);
});

it('formats quantities, money, percentages and dates as text', function () {
    expect(Display::quantity('1234567.50'))->toBe('1,234,567.5')
        ->and(Display::quantity('2800.00'))->toBe('2,800')
        ->and(Display::quantity('0.00'))->toBe('0')
        ->and(Display::quantity(null))->toBe(Display::EMPTY)
        ->and(Display::money('1234.5'))->toBe('1,234.50')
        ->and(Display::money('999999999999.994'))->toBe('999,999,999,999.99')
        ->and(Display::percent('62.5'))->toBe('62.50%')
        ->and(Display::wholeDays('12.50'))->toBe('13')
        ->and(Display::wholeDays('12.49'))->toBe('12')
        ->and(Display::date(CarbonImmutable::parse('2027-03-31')))->toBe('31 Mar 2027');
});

// 4. Expiry warning ----------------------------------------------------------------------

it('sends the at-risk and expired batches as structured items', function () {
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY');
    $today = CarbonImmutable::now('America/Port_of_Spain')->startOfDay();
    saveDraft($case, $vendor, [
        'requisition' => ['average_monthly_consumption' => '30', 'no_stock_on_hand' => false],
        'stock_expiries' => [
            ['quantity' => '100', 'expiry_date' => $today->addDays(10)->toDateString()],  // 100 days of cover, expires in 10: at risk
            ['quantity' => '5', 'expiry_date' => $today->subDay()->toDateString()],       // expired
            ['quantity' => '10', 'expiry_date' => $today->addYears(2)->toDateString()],   // fine
        ],
    ]);

    $expiry = formProp($this, $case, $vendor)['expiry'];

    expect($expiry)->toBe([
        'days_on_hand' => '115', // 115 x 30 / 30
        'at_risk' => [[
            'batch' => 'Batch 2', // batches are listed earliest expiry first, as on the form
            'quantity' => '100',
            'expiry_date' => $today->addDays(10)->format('j M Y'),
            'cover_days' => '100',
            'days_to_expiry' => '10',
        ]],
        'expired' => [[
            'batch' => 'Batch 1',
            'quantity' => '5',
            'expiry_date' => $today->subDay()->format('j M Y'),
        ]],
    ]);
});

it('sends no expiry items when nothing is at risk', function () {
    [$case, $vendor] = v2DraftForForm('AD_HOC_EMERGENCY');

    expect(formProp($this, $case, $vendor)['expiry'])->toBe(['days_on_hand' => '12', 'at_risk' => [], 'expired' => []]);
});

it('is built from the same report as request_sections', function () {
    [$case, $vendor] = newDraft('TENDER_EXCESS');
    $props = pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk());

    $visible = collect($props['form']['sections'])->where('visible', true)->values();
    expect($visible->pluck('key')->all())->toBe(array_column($props['request_sections'], 'key'))
        ->and(RequestFormView::INCOMPLETE)->toBe('incomplete');
});

/** Per type: what the V2-1 rules require (contract reference, stock, quotations, variation). */
const V2_FORM_REQUIRES = [
    'AD_HOC_EMERGENCY' => ['contract' => false, 'stock' => true, 'quotes' => true, 'variation' => false],
    'AD_HOC_TENDER_UNABLE' => ['contract' => true, 'stock' => true, 'quotes' => true, 'variation' => false],
    'AD_HOC_NOT_AWARDED' => ['contract' => false, 'stock' => true, 'quotes' => true, 'variation' => false],
    'TENDER_EXCESS' => ['contract' => true, 'stock' => true, 'quotes' => true, 'variation' => false],
    'TENDER_VARIATION' => ['contract' => true, 'stock' => false, 'quotes' => false, 'variation' => true],
];

/** A complete draft of $code with its required files (as RequestSectionsTest's v2Draft). */
function v2DraftForForm(string $code): array
{
    [$case, $vendor] = newDraft($code);
    saveCompleteAdHoc($case, $vendor);
    attachRequiredDocuments($case);

    return [ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor];
}

// 5. The edit page (V2-2 part 6) ---------------------------------------------------------

it('renders the request form with the form prop for every type', function (string $code) {
    [$case, $vendor] = newDraft($code);

    $this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk()
        ->assertInertia(fn ($page) => $page->component('requests/ad-hoc/edit')
            ->has('form.sections', count(RequestSections::ORDER))
            ->has('form.display.rows')
            ->has('form.expiry.at_risk')
            ->where('case.request_type', $code)
            ->has('request_sections'));
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('returns the form prop alone on a partial reload', function () {
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY');
    $version = $this->actingAs($vendor)->get(route('requests.edit', $case->id))->viewData('page')['version'];

    $props = $this->actingAs($vendor)->get(route('requests.edit', $case->id), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'requests/ad-hoc/edit',
        'X-Inertia-Partial-Data' => 'form',
    ])->assertOk()->json('props');

    expect($props)->toHaveKey('form')
        ->and($props)->not->toHaveKey('case')
        ->and($props)->not->toHaveKey('request_sections');
});

it('gives a vendor of another organisation 404 for the form', function () {
    [$case] = newDraft('TENDER_EXCESS');
    $other = vendorUser(vendorOrg());

    $this->actingAs($other)->get(route('requests.edit', $case->id))->assertNotFound();
});

it('has no fetch or axios in the request form code', function () {
    $files = [
        ...glob(resource_path('js/components/requests/ad-hoc/*.ts*')),
        ...glob(resource_path('js/pages/requests/ad-hoc/*.tsx')),
        resource_path('js/components/ui/entry-table.tsx'),
        resource_path('js/components/ui/progress-strip.tsx'),
    ];
    expect(count($files))->toBeGreaterThan(15);

    $hits = collect($files)->filter(fn (string $file) => preg_match('/\bfetch\s*\(|axios|XMLHttpRequest/', file_get_contents($file)) === 1)
        ->map(fn (string $file) => basename($file))->values()->all();

    expect($hits)->toBe([]);
});
