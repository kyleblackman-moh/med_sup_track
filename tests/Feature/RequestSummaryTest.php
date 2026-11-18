<?php

use App\Enums\DocumentVisibility;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Http\Resources\Requests\RequestSummary;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequestSections;
use App\Submissions\SubmitCase;
use App\Support\Display;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// Slice V2-3: the one read-only request summary (RequestSummary) used by the
// Review step, the vendor's request page and the staff case page.
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
});

/** A complete draft of $code (both save steps, the variation for a Tender Variation). */
function summaryDraft(string $code = 'AD_HOC_TENDER_UNABLE'): array
{
    [$case, $vendor] = newDraft($code);
    saveCompleteAdHoc($case, $vendor);

    return [ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor];
}

/** The summary as $viewer gets it (signed in: the models' scopes follow the user, as in a request). */
function summaryOf(ProcurementCase $case, User $viewer, bool $forStaff = false): array
{
    test()->actingAs($viewer);

    return RequestSummary::for(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $viewer, $forStaff);
}

/** The section with $key. */
function summarySection(array $summary, string $key): ?array
{
    return collect($summary['sections'])->firstWhere('key', $key);
}

/** Every label => value pair of a section's field blocks. */
function summaryFields(array $summary, string $key): array
{
    return collect(summarySection($summary, $key)['blocks'])->where('type', 'fields')
        ->flatMap(fn (array $block) => collect($block['items'])->pluck('value', 'label'))->all();
}

/** A section's table block with $caption. */
function summaryTable(array $summary, string $key, string $caption): ?array
{
    return collect(summarySection($summary, $key)['blocks'])->where('type', 'table')->firstWhere('caption', $caption);
}

// 1. Sections, order and numbering -------------------------------------------------------

it('lists the type\'s visible sections in form order, numbered among the visible ones', function (string $code) {
    [$case, $vendor] = summaryDraft($code);
    $summary = summaryOf($case, $vendor);

    $visible = RequestSections::visible($code);
    expect(array_column($summary['sections'], 'key'))->toBe(array_column($visible, 'key'))
        ->and(array_column($summary['sections'], 'label'))->toBe(array_column($visible, 'label'))
        ->and(array_column($summary['sections'], 'number'))->toBe(range(1, count($visible)))
        ->and(count($visible))->toBe($code === 'TENDER_VARIATION' ? 7 : 6)
        ->and($summary['type_label'])->toBe(procurementTypeByCode($code)->name);
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('shows Variation details only for a Tender Variation', function (string $code) {
    [$case, $vendor] = summaryDraft($code);
    $summary = summaryOf($case, $vendor);

    if ($code === 'TENDER_VARIATION') {
        expect(summarySection($summary, 'variation_details')['number'])->toBe(2)
            ->and(summaryFields($summary, 'variation_details'))->toBe([
                'Variation type' => 'Price',
                'Existing approved detail' => 'Unit cost 1.25 under the current contract.',
                'Proposed revised detail' => 'Unit cost 1.40 from the next delivery.',
                'Effective date' => '1 Jan 2027',
            ]);
    } else {
        expect(summarySection($summary, 'variation_details'))->toBeNull();
    }
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('is null for a case that is not on the request form', function () {
    [$case, $vendor] = legacyDraft('AD_HOC_PHARM');

    expect(RequestSummary::for($case, $vendor))->toBeNull();
});

// 2. Awards and orders never depend on the tender outcome ---------------------------------

it('shows awards and orders without a tender outcome, and never the outcome', function () {
    [$case, $vendor] = summaryDraft();
    expect(DB::table('case_requisitions')->where('case_id', $case->id)->value('tender_outcome'))->toBeNull();

    $summary = summaryOf($case, $vendor);
    $awards = summaryTable($summary, 'awarded_supplier', 'Awarded suppliers');
    $orders = summaryTable($summary, 'existing_arrangement', 'Orders');

    expect(array_column($awards['rows'], 'label'))->toBe(['Supplier row 1', 'Supplier row 2'])
        ->and($awards['empty'])->toBeNull()
        ->and(array_column($orders['rows'], 'label'))->toBe(['Order row 1'])
        ->and($orders['rows'][0]['cells']['supplier'])->toBe('Supplier A')
        ->and(json_encode($summary))->not->toContain('Outcome');
});

it('says "None entered" for a table with no rows and a dash for empty optional values', function () {
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY');
    $summary = summaryOf($case, $vendor);

    expect(summaryTable($summary, 'awarded_supplier', 'Awarded suppliers')['empty'])->toBe(RequestSummary::NONE)
        ->and(summaryTable($summary, 'existing_arrangement', 'Orders')['empty'])->toBe(RequestSummary::NONE)
        ->and(summaryTable($summary, 'quotation_comparison', 'Quotations')['rows'])->toBe([])
        ->and(summaryFields($summary, 'item_tender')['Strength'])->toBe(Display::EMPTY)
        ->and(summaryFields($summary, 'stock')['Days on hand'])->toBe(Display::EMPTY);
});

// 3. Every V2-1 field ----------------------------------------------------------------------

it('includes every V2-1 field', function () {
    [$case, $vendor] = summaryDraft();
    $summary = summaryOf($case, $vendor);
    $item = summaryFields($summary, 'item_tender');
    $awards = summaryTable($summary, 'awarded_supplier', 'Awarded suppliers');
    $quotes = summaryTable($summary, 'quotation_comparison', 'Quotations');

    expect($item)->toMatchArray([
        'Request type' => 'Ad Hoc - Tender unable to supply',
        'DIN' => '02345678',
        'Contract reference' => 'TENDER-2026-14 (2026-2027)',
        'Tender cycle start' => '1 Jan 2026',
        'Tender cycle end' => '31 Dec 2027',
    ])
        ->and(summaryFields($summary, 'stock')['Quantity on order'])->toBe('1,800 Each')
        ->and($awards['rows'][0]['cells']['country_of_origin'])->toBe('Country A')
        ->and($awards['rows'][1]['cells']['country_of_origin'])->toBe(Display::EMPTY)
        ->and(array_column($quotes['columns'], 'label'))->toContain('Ex stock', 'Lead time (days)', 'Remarks', 'Country of origin')
        ->and($quotes['rows'][1]['cells'])->toMatchArray([
            'ex_stock' => 'Yes', 'lead_time_days' => '30', 'remarks' => 'Two-week delivery.', 'country_of_origin' => 'Country B', 'recommended' => 'Yes',
        ])
        ->and($quotes['rows'][0]['cells'])->toMatchArray(['considered' => 'No', 'reason' => 'No registration on file.', 'ex_stock' => Display::EMPTY]);
});

// 4. Computed values, formatted by the server ----------------------------------------------

it('computes and formats every figure on the server', function () {
    [$case, $vendor] = summaryDraft();
    $summary = summaryOf($case, $vendor);
    $awards = summaryTable($summary, 'awarded_supplier', 'Awarded suppliers');

    expect(summaryFields($summary, 'awarded_supplier'))->toBe([
        'Number of suppliers awarded' => '2',
        'Total cost of the awards' => '5,050.00',
    ])
        ->and(array_map(fn ($row) => [$row['cells']['percentage'], $row['cells']['line_cost'], $row['cells']['unit_cost']], $awards['rows']))
        ->toBe([['75.00%', '3,750.00', '1.25'], ['25.00%', '1,300.00', '1.30']])
        ->and(summaryTable($summary, 'existing_arrangement', 'Orders')['rows'][0]['cells']['quantity_outstanding'])->toBe('1,800')
        ->and(summaryFields($summary, 'stock'))->toMatchArray(['Stock total' => '200.5 Each', 'Days on hand' => '12', 'No stock on hand' => 'No'])
        ->and(summaryFields($summary, 'quotation_comparison'))->toMatchArray([
            'RFQ sent to' => 'All registered suppliers',
            'Number of quotations' => '2',
            'Quotations considered' => '1',
            'Recommended quotation' => 'Supplier D',
            'Recommended unit price' => '1.5975',
            'Quantity to purchase' => '2,800 Each',
            'Total cost' => '4,473.00',
            'Change against tender price' => '27.80%',
            'Change against previous price' => '6.50%',
            'Quoted price is acceptable' => 'Yes',
        ]);
});

it('words the expiry warning from the submission date once submitted', function () {
    [$case, $vendor] = readyDraft('AD_HOC_EMERGENCY');
    $submitted = CarbonImmutable::now('America/Port_of_Spain')->startOfDay()->subDays(60);
    $expiry = $submitted->addDays(10); // expired today, 10 days ahead on the submission date
    saveDraft($case, $vendor, [
        'requisition' => ['average_monthly_consumption' => '30', 'no_stock_on_hand' => false],
        'stock_expiries' => [['quantity' => '100', 'expiry_date' => $expiry->toDateString()]],
    ]);

    // A DRAFT is measured from today: the batch has expired.
    $draftWarning = collect(summarySection(summaryOf($case, $vendor), 'stock')['blocks'])->firstWhere('type', 'warning');
    expect($draftWarning['lines'])->toBe(['Batch expiring '.$expiry->format('j M Y').' has expired']);

    // Submitted (the real flow), then dated 60 days ago (test setup: submitted_at only, not the status).
    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);
    DB::table('cases')->where('id', $case->id)->update(['submitted_at' => $submitted->utc()]);

    $warning = collect(summarySection(summaryOf($case, $vendor), 'stock')['blocks'])->firstWhere('type', 'warning');
    expect($warning['title'])->toBe('Some stock may expire before it is used')
        ->and($warning['lines'])->toBe(['Batch expiring '.$expiry->format('j M Y').': about 100 days of cover, expires in 10 days']);
});

// 5. Documents and isolation -------------------------------------------------------------------

it('lists the documents with the existing visibility rules', function () {
    [$case, $vendor] = readyDraft('AD_HOC_TENDER_UNABLE');
    makeDocument($case, 'bids', ['original_name' => 'bids.pdf']);
    makeDocument($case, 'bids', ['original_name' => 'scanning.pdf', 'scan_status' => ScanStatus::PENDING, 'released_at' => null]);
    makeDocument($case, 'bids', ['original_name' => 'internal.pdf', 'visibility' => DocumentVisibility::INTERNAL]);

    $names = fn (array $summary) => array_map(fn ($row) => $row['cells']['name'], summaryTable($summary, 'documents', 'Documents')['rows']);

    // Vendor: never internal files; files still being scanned are listed with their status.
    expect($names(summaryOf($case, $vendor)))->toContain('bids.pdf', 'scanning.pdf')->not->toContain('internal.pdf');
    // Staff (who never see a DRAFT): CLEAN files only, as the staff documents list.
    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);
    expect($names(summaryOf($case, caseUser(Role::Cmo), forStaff: true)))->toContain('bids.pdf')->not->toContain('scanning.pdf');
});

it('never contains values from another case', function () {
    [$mine, $vendor] = summaryDraft();
    [$other, $otherVendor] = newDraft('AD_HOC_TENDER_UNABLE');
    saveDraft($other, $otherVendor, [
        'requisition' => ['item_description' => 'Other organisation item', 'din' => '99999999'],
        'tender_awards' => [['supplier_name' => 'Other supplier', 'quantity_awarded' => '5', 'unit_cost' => '9.99']],
    ]);
    makeDocument($other, 'bids', ['original_name' => 'other-case.pdf']);

    $json = json_encode(summaryOf($mine, $vendor));

    expect($json)->not->toContain('Other organisation item')
        ->not->toContain('99999999')
        ->not->toContain('Other supplier')
        ->not->toContain('other-case.pdf');
});

// 6. The three pages show the same summary ---------------------------------------------------

it('gives the staff case page and the vendor page the same summary of a submitted case', function () {
    [$case, $vendor] = readyDraft('TENDER_VARIATION');
    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);

    $vendorSummary = pageProps($this->actingAs($vendor)->get(route('requests.show', $case->id))->assertOk())['summary'];
    $staffSummary = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case->id))->assertOk())['summary'];

    // Identical apart from the documents, which follow each audience's rules (staff: a Visibility column).
    $withoutDocuments = fn (array $summary) => collect($summary['sections'])->reject(fn ($s) => $s['key'] === 'documents')->values()->all();
    expect($withoutDocuments($staffSummary))->toBe($withoutDocuments($vendorSummary))
        ->and(array_column($staffSummary['sections'], 'number', 'key'))->toBe(array_column($vendorSummary['sections'], 'number', 'key'))
        ->and(summarySection($staffSummary, 'variation_details')['number'])->toBe(2);
});

it('keeps staff away from drafts on the case page, old types included', function () {
    [$legacy] = legacyDraft('AD_HOC_PHARM');
    // A DRAFT is never shown to staff (404, as today).
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $legacy->id))->assertNotFound();

    [$case] = summaryDraft('AD_HOC_EMERGENCY');
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case->id))->assertNotFound();
});

// 7. Static guards --------------------------------------------------------------------------

it('has no fetch or axios in the summary and the pages that show it', function () {
    $files = [
        resource_path('js/components/requests/request-summary.tsx'),
        resource_path('js/components/requests/ad-hoc/review.tsx'),
        resource_path('js/pages/requests/show.tsx'),
        resource_path('js/pages/cases/show.tsx'),
    ];

    foreach ($files as $file) {
        expect(preg_match('/\bfetch\s*\(|axios|XMLHttpRequest/', file_get_contents($file)))->toBe(0, basename($file));
    }
});

it('never adds a "%" sign to a value in React', function () {
    // The server formats percentages (Display::percent). Two non-data uses stay: a
    // skeleton's random CSS width (sidebar) and the browser's own upload progress.
    $allowed = ['sidebar.tsx', 'upload-drop-zone.tsx'];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    $hits = [];
    foreach ($files as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true) || in_array($file->getFilename(), $allowed, true)) {
            continue;
        }
        foreach (preg_split('/\R/', file_get_contents($file->getPathname())) as $i => $line) {
            $code = trim($line);
            if (str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                continue; // comments
            }
            if (preg_match('/\}\s*%|[\'"`]%[\'"`]|\+\s*[\'"]%/', $code)) {
                $hits[] = $file->getFilename().':'.($i + 1);
            }
        }
    }

    expect($hits)->toBe([]);
});
