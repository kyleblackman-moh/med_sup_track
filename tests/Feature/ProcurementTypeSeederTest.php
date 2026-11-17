<?php

use App\Enums\DocumentPurpose;
use App\Models\ProcurementType;
use App\Models\ProcurementTypeDocument;
use Database\Seeders\ProcurementTypeSeeder;
use Illuminate\Support\Facades\DB;

// ProcurementTypeSeeder: reference data. Seeded inside the test transaction,
// so it is rolled back like everything else.

beforeEach(function () {
    $this->seed(ProcurementTypeSeeder::class);
});

// Slice F1: AD_HOC (no category) replaces the Pharm / Non-Pharm ad hoc types,
// which stay listed but inactive. Changed on purpose.
const EXPECTED_TYPES = [
    // code => [name, category, is_active, sort_order]
    'AD_HOC' => ['Ad hoc purchase', null, true, 10],
    'AD_HOC_PHARM' => ['Ad hoc purchase (Pharm)', 'PHARM', false, 20],
    'AD_HOC_NON_PHARM' => ['Ad hoc purchase (Non-Pharm)', 'NON_PHARM', false, 30],
    'TENDER_EXCESS' => ['Purchase in excess of tender', 'PHARM', true, 40],
    'TENDER_VARIATION' => ['Tender variation', 'PHARM', false, 50],
];

const AD_HOC_REQUIRED = [
    ['original_rfq', 'Original RFQ / procurement request', true],
    ['supplier_quotations', 'All supplier quotations', true],
    ['evaluation_report', 'Evaluation and reasons for exclusions', true],
    ['technical_evidence', 'Technical / sample assessment evidence', true],
    ['signed_request', 'Signed request from the applicant', true],
    ['stock_evidence', 'Stock position and consumption evidence', true],
];

/** code => ordered list of [purpose, label, required]. */
function expectedDocuments(): array
{
    return [
        'AD_HOC' => [
            ['bids', 'Bids', true],
            ['regulatory', 'Regulatory documents', false],
            ['evidence', 'Evidence', false],
        ],
        'AD_HOC_PHARM' => [
            ...AD_HOC_REQUIRED,
            ['other_supporting', 'Other supporting justification', false],
        ],
        'AD_HOC_NON_PHARM' => [
            ...AD_HOC_REQUIRED,
            ['product_specifications', 'Product specifications / technical datasheets', false],
            ['rha_sample_confirmation', 'RHA / institution sample evaluation confirmations', false],
            ['supplier_correspondence', 'Supplier correspondence and lead-time confirmation', false],
            ['other_supporting', 'Other supporting justification', false],
        ],
        'TENDER_EXCESS' => [
            ['signed_request', 'Signed request from the applicant', true],
            ['stock_evidence', 'Stock position and consumption evidence', true],
            ['supplier_confirmation', 'Supplier confirmation / revalidation of bid', false],
            ['tender_pricing_confirmation', 'Quotation or tender pricing confirmation', false],
            ['purchase_orders_delivery', 'Purchase orders and delivery records', false],
            ['outstanding_order_status', 'Outstanding order / shipment status', false],
            ['supplier_correspondence', 'Supplier correspondence and lead-time confirmation', false],
            ['other_supporting', 'Other supporting justification', false],
        ],
        'TENDER_VARIATION' => [],
    ];
}

/** Stored documents for a type, ordered by sort_order, as [purpose, label, required]. */
function storedDocuments(string $code): array
{
    return ProcurementType::where('code', $code)->firstOrFail()->documents
        ->map(fn (ProcurementTypeDocument $d) => [$d->purpose->value, $d->label, $d->is_required])
        ->all();
}

function seededCounts(): array
{
    return [
        'types' => DB::table('procurement_types')->count(),
        'documents' => DB::table('procurement_type_documents')->count(),
    ];
}

it('seeds exactly the five procurement types', function () {
    $actual = ProcurementType::orderBy('sort_order')->get()->mapWithKeys(fn (ProcurementType $t) => [
        $t->code => [$t->name, $t->category?->value, $t->is_active, $t->sort_order],
    ])->all();

    expect($actual)->toBe(EXPECTED_TYPES);
});

it('seeds exactly the listed documents for each type, in order', function (string $code) {
    expect(storedDocuments($code))->toBe(expectedDocuments()[$code]);
})->with(array_keys(EXPECTED_TYPES));

it('leaves TENDER_VARIATION inactive with no documents', function () {
    $type = ProcurementType::where('code', 'TENDER_VARIATION')->firstOrFail();

    expect($type->is_active)->toBeFalse()
        ->and($type->documents()->count())->toBe(0);
});

it('requires Bids only for AD_HOC, six documents for the old ad hoc types and two for TENDER_EXCESS', function () {
    $required = fn (string $code) => collect(storedDocuments($code))->filter(fn ($d) => $d[2])->pluck(0)->all();
    $sixRequired = array_column(AD_HOC_REQUIRED, 0);

    expect($required('AD_HOC'))->toBe(['bids'])
        ->and($required('AD_HOC_PHARM'))->toBe($sixRequired)
        ->and($required('AD_HOC_NON_PHARM'))->toBe($sixRequired)
        ->and($required('TENDER_EXCESS'))->toBe(['signed_request', 'stock_evidence']);
});

it('numbers sort_order 10, 20, 30... within each type, uniquely', function () {
    foreach (ProcurementType::with('documents')->get() as $type) {
        $orders = $type->documents->pluck('sort_order')->all();

        expect($orders)->toBe(array_map(fn ($i) => ($i + 1) * 10, array_keys($orders)))
            ->and(array_unique($orders))->toBe($orders);
    }
});

it('stores only valid DocumentPurpose values and no duplicate (type, purpose)', function () {
    $rows = DB::table('procurement_type_documents')->get(['procurement_type_id', 'purpose']);

    foreach ($rows as $row) {
        expect(DocumentPurpose::tryFrom($row->purpose))->not->toBeNull();
    }

    expect($rows->map(fn ($r) => $r->procurement_type_id.'|'.$r->purpose)->duplicates()->all())->toBe([]);
});

it('is idempotent', function () {
    $before = seededCounts();

    $this->seed(ProcurementTypeSeeder::class);

    expect(seededCounts())->toBe($before);
});

it('corrects a changed label, required flag or type field, and removes unlisted documents', function () {
    $type = ProcurementType::where('code', 'AD_HOC_PHARM')->firstOrFail();
    $type->documents()->where('purpose', 'original_rfq')->update(['label' => 'Edited', 'is_required' => false]);
    $type->documents()->create(['purpose' => 'purchase_orders_delivery', 'label' => 'Stale', 'is_required' => true, 'sort_order' => 99]);
    $type->update(['name' => 'Edited name', 'is_active' => true]);

    $this->seed(ProcurementTypeSeeder::class);

    // Slice F1: AD_HOC_PHARM is inactive now, so the seeder resets it to false (was true).
    expect(storedDocuments('AD_HOC_PHARM'))->toBe(expectedDocuments()['AD_HOC_PHARM'])
        ->and($type->fresh()->name)->toBe('Ad hoc purchase (Pharm)')
        ->and($type->fresh()->is_active)->toBeFalse();
});

it('gives each AD_HOC category a short hint and the old types none', function () {
    $hints = fn (string $code) => ProcurementType::where('code', $code)->firstOrFail()->documents
        ->mapWithKeys(fn (ProcurementTypeDocument $d) => [$d->purpose->value => $d->hint])->all();

    foreach ($hints('AD_HOC') as $hint) {
        expect($hint)->toBeString()->not->toBe('')
            ->and(mb_strlen($hint))->toBeLessThanOrEqual(255);
    }
    expect(array_filter($hints('AD_HOC_PHARM')))->toBe([]);
});

it('matches AD_HOC by code: one row, same id, config corrected on every run', function () {
    $type = ProcurementType::where('code', 'AD_HOC')->sole();
    $type->documents()->where('purpose', 'bids')->update(['is_required' => false, 'hint' => null]);

    $this->seed(ProcurementTypeSeeder::class);

    expect(ProcurementType::where('code', 'AD_HOC')->pluck('id')->all())->toBe([$type->id])
        ->and(storedDocuments('AD_HOC'))->toBe(expectedDocuments()['AD_HOC'])
        ->and($type->documents()->where('purpose', 'bids')->value('hint'))->not->toBeNull();
});

it('contains no vendor name in the seeder source', function () {
    $source = file_get_contents(database_path('seeders/ProcurementTypeSeeder.php'));

    expect(stripos($source, 'nipdec'))->toBeFalse();
});
