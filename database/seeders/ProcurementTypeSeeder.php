<?php

namespace Database\Seeders;

use App\Enums\DocumentPurpose as D;
use App\Enums\ProcurementCategory;
use App\Models\ProcurementType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Procurement types and their document checklists. Reference data: runs in
 * every environment (registered in DatabaseSeeder). Idempotent: every type is
 * matched by code (updateOrCreate), so it never collides with a row created
 * elsewhere (the F1 data migration inserts AD_HOC if it is missing).
 *
 * Bootstrap defaults, draft until the CMO's office confirms (Q22, Q24).
 * Switch to create-only once an admin screen can edit these.
 *
 * Every type stays listed, active or not: the remove-unlisted step below would
 * otherwise delete the document configuration of a type that still has cases.
 */
class ProcurementTypeSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ($this->types() as $sortOrder => [$code, $name, $category, $active, $documents]) {
                $type = ProcurementType::updateOrCreate(
                    ['code' => $code],
                    ['name' => $name, 'category' => $category, 'is_active' => $active, 'sort_order' => ($sortOrder + 1) * 10],
                );

                foreach ($documents as $i => [$purpose, $label, $required, $hint]) {
                    $type->documents()->updateOrCreate(
                        ['purpose' => $purpose->value],
                        ['label' => $label, 'is_required' => $required, 'hint' => $hint, 'sort_order' => ($i + 1) * 10],
                    );
                }

                // Remove documents no longer listed for this type.
                $type->documents()
                    ->whereNotIn('purpose', array_map(fn (array $d) => $d[0]->value, $documents))
                    ->delete();
            }
        });
    }

    /** @return list<array{0: string, 1: string, 2: ProcurementCategory|null, 3: bool, 4: list<array{0: D, 1: string, 2: bool, 3: string|null}>}> */
    private function types(): array
    {
        $adHocRequired = [
            [D::original_rfq, 'Original RFQ / Procurement Request', true, null],
            [D::supplier_quotations, 'All Supplier Quotations', true, null],
            [D::evaluation_report, 'Evaluation and Reasons for Exclusions', true, null],
            [D::technical_evidence, 'Technical / Sample Assessment Evidence', true, null],
            [D::signed_request, 'Signed Request From the Applicant', true, null],
            [D::stock_evidence, 'Stock Position and Consumption Evidence', true, null],
        ];
        $otherSupporting = [D::other_supporting, 'Other Supporting Justification', false, null];
        $supplierCorrespondence = [D::supplier_correspondence, 'Supplier Correspondence and Lead-Time Confirmation', false, null];

        // Slice V2-1: the three drop-zone categories of the request form. Hints are
        // guidance only and enforce nothing (open question for the CMO's office).
        $formDocuments = fn (bool $bidsRequired) => [
            [D::bids, 'Bids', $bidsRequired, 'For example the RFQ, the comparison sheet and the supplier quotations.'],
            [D::regulatory, 'Regulatory Documents', false, 'For example registration and product specifications.'],
            [D::evidence, 'Evidence', false, 'For example stock and usage records, correspondence and sample reports.'],
        ];

        return [
            // Slice V2-1: the five request types, all on the one request form.
            // Bids is required for the three Ad Hoc types; no file for Excess or Variation.
            ['AD_HOC_EMERGENCY', 'Ad Hoc - Emergency', ProcurementCategory::AD_HOC, true, $formDocuments(true)],
            ['AD_HOC_TENDER_UNABLE', 'Ad Hoc - Tender Unable to Supply', ProcurementCategory::AD_HOC, true, $formDocuments(true)],
            ['AD_HOC_NOT_AWARDED', 'Ad Hoc - Not Awarded', ProcurementCategory::AD_HOC, true, $formDocuments(true)],
            // Existing row, kept by code.
            ['TENDER_EXCESS', 'Tender - Excess', null, true, $formDocuments(false)],
            ['TENDER_VARIATION', 'Tender - Variation', null, true, $formDocuments(false)],
            // The single Ad Hoc form of slice F1. Replaced by the three Ad Hoc types;
            // inactive, kept (with its documents) so its cases stay readable.
            ['AD_HOC', 'Ad Hoc Purchase', null, false, $formDocuments(true)],
            // Replaced by AD_HOC; inactive, kept for their existing cases.
            ['AD_HOC_PHARM', 'Ad Hoc Purchase (Pharm)', ProcurementCategory::PHARM, false, [
                ...$adHocRequired,
                $otherSupporting,
            ]],
            ['AD_HOC_NON_PHARM', 'Ad Hoc Purchase (Non-Pharm)', ProcurementCategory::NON_PHARM, false, [
                ...$adHocRequired,
                [D::product_specifications, 'Product Specifications / Technical Datasheets', false, null],
                [D::rha_sample_confirmation, 'RHA / Institution Sample Evaluation Confirmations', false, null],
                $supplierCorrespondence,
                $otherSupporting,
            ]],
            // Slice V2-1b: a new draft's type until the vendor chooses one in the form.
            // Inactive, so it is never offered as a choice; no file is required.
            ['NOT_SELECTED', 'Not Selected', null, false, $formDocuments(false)],
        ];
    }
}
