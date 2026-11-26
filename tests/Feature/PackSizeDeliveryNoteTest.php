<?php

use App\Http\Resources\Requests\RequestSummary;
use App\Models\CasePurchaseOrder;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
use App\Submissions\RequestSections;
use App\Submissions\SubmissionReadiness;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-25: optional free text on the request form's rows: Pack size on
// award and order rows, Delivery note on order rows (100 characters each).
// Never required to submit. Kyle, 2026-11-26: Pack Size left the form, the
// summary and the letter (the column stays; still accepted, never asked).
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

/** A ready draft whose first award and first order carry a pack size and a delivery note. */
function pdDraft(array $award = ['pack_size' => 'Boxes of 28 Tablets'], array $order = ['pack_size' => 'Boxes of 28 Tablets', 'delivery_note' => 'Variation of tender in progress']): array
{
    [$case, $vendor] = readyDraft('AD_HOC_TENDER_UNABLE');
    $payload = adHocPayload();
    $payload['tender_awards'][0] = [...$payload['tender_awards'][0], 'id' => CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->value('id'), ...$award];
    $payload['tender_awards'][1]['id'] = CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->skip(1)->value('id');
    saveDraft($case, $vendor, ['tender_awards' => $payload['tender_awards']]);

    $orders = adHocOrdersFor($case)['purchase_orders'];
    $orders[0] = [...$orders[0], 'id' => CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->value('id'), ...$order];
    saveDraft($case, $vendor, ['purchase_orders' => $orders]);

    return [ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor];
}

it('saves pack size and delivery note on the rows', function () {
    [$case] = pdDraft();

    expect(CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->value('pack_size'))->toBe('Boxes of 28 Tablets')
        ->and(CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->first(['pack_size', 'delivery_note'])->toArray())
        ->toBe(['pack_size' => 'Boxes of 28 Tablets', 'delivery_note' => 'Variation of tender in progress']);
});

it('sends them to the form and shows them in the summary', function () {
    [$case, $vendor] = pdDraft();
    $props = pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk());

    expect($props['case']['tender_awards'][0]['pack_size'])->toBe('Boxes of 28 Tablets')
        ->and($props['case']['purchase_orders'][0])->toMatchArray(['pack_size' => 'Boxes of 28 Tablets', 'delivery_note' => 'Variation of tender in progress']);

    $summary = json_encode(RequestSummary::for($case, $vendor), JSON_UNESCAPED_UNICODE);
    expect($summary)->not->toContain('Pack Size')->not->toContain('Boxes of 28 Tablets')
        ->toContain('Delivery Note No.')->toContain('Variation of tender in progress');
});

it('never requires them', function () {
    [$case] = pdDraft([], []);

    expect(app(SubmissionReadiness::class)->evaluate($case)->errors)->toBe([])
        ->and(RequestSections::requiredFields('AD_HOC_TENDER_UNABLE')['awarded_supplier'])->not->toHaveKey('tender_awards.pack_size')
        ->and(RequestSections::requiredFields('AD_HOC_TENDER_UNABLE')['existing_arrangement'])->not->toHaveKey('purchase_orders.pack_size')
        ->toMatchArray(['purchase_orders.delivery_note' => false]);
});

it('refuses more than 100 characters', function (string $section, string $field) {
    [$case, $vendor] = readyDraft('AD_HOC_TENDER_UNABLE');
    $rows = $section === 'tender_awards' ? adHocPayload()['tender_awards'] : adHocOrdersFor($case)['purchase_orders'];
    $rows[0][$field] = str_repeat('x', 101);

    saveDraft($case, $vendor, [$section => $rows]);
})->throws(ValidationException::class)->with([
    ['tender_awards', 'pack_size'],
    ['purchase_orders', 'pack_size'],
    ['purchase_orders', 'delivery_note'],
]);

it('asks for no pack size on the form (Kyle, 2026-11-26)', function () {
    foreach (['awarded-supplier-section.tsx', 'existing-arrangement-section.tsx'] as $file) {
        expect(file_get_contents(resource_path("js/components/requests/ad-hoc/{$file}")))->not->toContain('pack_size')->not->toContain('Pack Size');
    }
    expect(file_get_contents(resource_path('js/components/requests/ad-hoc/form-state.ts')))->not->toContain('pack_size');
});
