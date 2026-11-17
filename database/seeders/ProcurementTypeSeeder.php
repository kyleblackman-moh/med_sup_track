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
            [D::original_rfq, 'Original RFQ / procurement request', true, null],
            [D::supplier_quotations, 'All supplier quotations', true, null],
            [D::evaluation_report, 'Evaluation and reasons for exclusions', true, null],
            [D::technical_evidence, 'Technical / sample assessment evidence', true, null],
            [D::signed_request, 'Signed request from the applicant', true, null],
            [D::stock_evidence, 'Stock position and consumption evidence', true, null],
        ];
        $otherSupporting = [D::other_supporting, 'Other supporting justification', false, null];
        $supplierCorrespondence = [D::supplier_correspondence, 'Supplier correspondence and lead-time confirmation', false, null];

        return [
            // The single Ad Hoc form (slice F1). No Pharm / Non-Pharm category.
            // Hints are guidance only and enforce nothing (open question for the CMO's office).
            ['AD_HOC', 'Ad hoc purchase', null, true, [
                [D::bids, 'Bids', true, 'For example the RFQ, the comparison sheet and the supplier quotations.'],
                [D::regulatory, 'Regulatory documents', false, 'For example registration and product specifications.'],
                [D::evidence, 'Evidence', false, 'For example stock and usage records, correspondence and sample reports.'],
            ]],
            // Replaced by AD_HOC; inactive, kept for their existing cases.
            ['AD_HOC_PHARM', 'Ad hoc purchase (Pharm)', ProcurementCategory::PHARM, false, [
                ...$adHocRequired,
                $otherSupporting,
            ]],
            ['AD_HOC_NON_PHARM', 'Ad hoc purchase (Non-Pharm)', ProcurementCategory::NON_PHARM, false, [
                ...$adHocRequired,
                [D::product_specifications, 'Product specifications / technical datasheets', false, null],
                [D::rha_sample_confirmation, 'RHA / institution sample evaluation confirmations', false, null],
                $supplierCorrespondence,
                $otherSupporting,
            ]],
            ['TENDER_EXCESS', 'Purchase in excess of tender', ProcurementCategory::PHARM, true, [
                [D::signed_request, 'Signed request from the applicant', true, null],
                [D::stock_evidence, 'Stock position and consumption evidence', true, null],
                [D::supplier_confirmation, 'Supplier confirmation / revalidation of bid', false, null],
                [D::tender_pricing_confirmation, 'Quotation or tender pricing confirmation', false, null],
                [D::purchase_orders_delivery, 'Purchase orders and delivery records', false, null],
                [D::outstanding_order_status, 'Outstanding order / shipment status', false, null],
                $supplierCorrespondence,
                $otherSupporting,
            ]],
            // Inactive. The category is a placeholder: there is no template for
            // tender variations yet, so no documents either.
            ['TENDER_VARIATION', 'Tender variation', ProcurementCategory::PHARM, false, []],
        ];
    }
}
