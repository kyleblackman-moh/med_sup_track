<?php

namespace App\Submissions;

use App\Enums\ProcurementCategory;
use App\Enums\ScanStatus;
use App\Enums\TechnicalStatus;
use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Models\CaseVariationRow;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Models\ProcurementTypeDocument;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Is a draft complete enough to submit? Read-only.
 *
 * Reads the case's rows without the visibility scopes: the CALLER must already
 * have authorised access to $case. Decimal comparisons are exact (bcmath).
 *
 * Draft rules; confirm with the CMO's office (Q22, Q24).
 */
final class SubmissionReadiness
{
    private const LOCAL_TIMEZONE = 'America/Port_of_Spain';

    private array $errors = [];

    private array $warnings = [];

    public function __construct(private readonly RequestSections $sections = new RequestSections) {}

    public function evaluate(ProcurementCase $case): ReadinessReport
    {
        $this->errors = [];
        $this->warnings = [];

        $type = $case->procurementType()->firstOrFail();
        $profile = ProcurementTypeProfile::for($type);
        $caseId = $case->getKey();

        $requisition = CaseRequisition::withoutGlobalScopes()->where('case_id', $caseId)->first() ?? new CaseRequisition;
        $rows = [
            'quotations' => $this->rows(CaseQuotation::class, $caseId),
            'tender_awards' => $this->rows(CaseTenderAward::class, $caseId),
            'purchase_orders' => $this->rows(CasePurchaseOrder::class, $caseId),
            'stock_expiries' => CaseStockExpiry::withoutGlobalScopes()->where('case_id', $caseId)->orderBy('expiry_date')->orderBy('id')->get(),
            'variation_rows' => $this->rows(CaseVariationRow::class, $caseId), // V2-5
        ];

        if ($profile->isAdHocForm()) {
            // Slice V2-1: the request form's rules per type (RequestSections). An old
            // AD_HOC draft is told to choose one of the five types first.
            $this->errors = $this->sections->errors($type->code, $requisition, $rows, self::previousRequest($case));

            foreach ($rows['quotations'] as $i => $q) {
                if ($q->technical_status !== TechnicalStatus::PENDING) {
                    $this->warning("quotations.{$i}.technical_status", 'MOH has already assessed this quotation.');
                }
            }

            // Slice V2-1: the expiry warning. Advisory only, never an error.
            if (! $requisition->no_stock_on_hand) {
                $this->expiryWarnings($requisition, $rows['stock_expiries'], ExpiryRisk::referenceDate($case));
            }

            $this->checkDocuments($type, $caseId);

            return new ReadinessReport($this->errors, $this->warnings, []);
        }

        // Old types (AD_HOC_PHARM, AD_HOC_NON_PHARM; inactive): unchanged.
        $this->checkRequisition($requisition, $type->category, $profile);

        foreach ($profile->requiredSections as $section) {
            if ($rows[$section]->isEmpty()) {
                $this->error($section, 'Add at least one row.');
            }
        }

        $this->checkQuotations($rows['quotations'], $requisition);

        if ($type->category === ProcurementCategory::PHARM
            && ($stock = $requisition->stockOnHand()) !== null
            && Decimal::compare($stock, '0') > 0
            && $rows['stock_expiries']->isEmpty()) {
            $this->warning('stock_expiries', 'Stock is on hand but no expiry dates are listed.');
        }

        $this->checkDocuments($type, $caseId);

        return new ReadinessReport($this->errors, $this->warnings, []);
    }

    /**
     * One warning per batch at risk or expired (ExpiryRisk), keyed to the
     * batch's expiry date like the payload ("stock_expiries.<index>.expiry_date").
     */
    private function expiryWarnings(CaseRequisition $requisition, Collection $batches, CarbonImmutable $today): void
    {
        $risk = ExpiryRisk::compute($requisition->average_monthly_consumption, false, $batches, $today);

        foreach ($batches->values() as $i => $batch) {
            $warning = $risk['expiry_warnings'][(int) $batch->getKey()] ?? null;
            if ($warning === null) {
                continue;
            }

            $n = $i + 1;
            $this->warning("stock_expiries.{$i}.expiry_date", $warning['state'] === ExpiryRisk::EXPIRED
                ? "Stock batch {$n} is past its expiry date."
                : "Stock batch {$n} may expire before it is used: about {$warning['cover_days']} days of stock on hand, and this batch (the earliest to expire) expires in {$warning['days_until_expiry']} days.");
        }
    }

    /**
     * Each required purpose needs at least one CURRENT, CLEAN document (slice 8c).
     * Counts every current document on the case, whoever uploaded it.
     */
    private function checkDocuments(ProcurementType $type, string $caseId): void
    {
        $statuses = Document::withoutGlobalScopes()->current()
            ->where('case_id', $caseId)
            ->get(['purpose', 'scan_status'])
            ->groupBy(fn (Document $d) => $d->purpose->value)
            ->map(fn ($docs) => $docs->pluck('scan_status')->all());

        $required = ProcurementTypeDocument::where('procurement_type_id', $type->getKey())
            ->where('is_required', true)->orderBy('sort_order')->get();

        foreach ($required as $requirement) {
            $purpose = $requirement->purpose->value;
            $found = $statuses->get($purpose, []);

            if (in_array(ScanStatus::CLEAN, $found, true)) {
                continue;
            }

            $this->error("documents.{$purpose}", match (true) {
                $found === [] => "Required document missing: {$requirement->label}",
                in_array(ScanStatus::PENDING, $found, true) => "{$requirement->label} is still being virus-checked. Try again in a moment.",
                default => "{$requirement->label} failed the security check; upload a replacement",
            });
        }
    }

    private function checkRequisition(CaseRequisition $r, ProcurementCategory $category, ProcurementTypeProfile $profile): void
    {
        foreach ([
            'vendor_reference' => 'Enter your reference.',
            'request_date' => 'Enter the request date.',
            'need_classification' => 'Choose a need classification.',
            'item_description' => 'Describe the item.',
            'unit_of_measure' => 'Enter the unit of measure.',
        ] as $field => $message) {
            $this->requirePresent($r, $field, $message);
        }

        $this->requirePositive($r, 'quantity_requested', 'Enter a quantity greater than zero.');
        $this->requirePositive($r, 'average_monthly_consumption', 'Enter an average monthly consumption greater than zero.');

        foreach (['central_stores_stock' => 'central stores', 'rha_stock' => 'RHA'] as $field => $label) {
            if ($r->{$field} === null) {
                $this->error("requisition.{$field}", "Enter the {$label} stock (0 if none).");
            }
        }

        if ($category === ProcurementCategory::PHARM) {
            $this->requirePresent($r, 'din', 'Enter the DIN.');
        }

        if ($profile->isAdHoc()) {
            $this->requirePresent($r, 'rfq_date', 'Enter the RFQ date.');
            $this->requirePresent($r, 'rfq_audience', 'Choose who received the RFQ.');
        }

        if ($profile->code === 'AD_HOC_NON_PHARM') {
            $this->requirePresent($r, 'tender_outcome', 'Choose the tender outcome.');
        }

        foreach ([
            'purpose' => 'No purpose given.',
            'urgency_explanation' => 'No urgency explanation given.',
            'tender_reference' => 'No tender reference given.',
            'stock_verified_on' => 'No stock verification date given.',
        ] as $field => $message) {
            if ($this->blank($r->{$field})) {
                $this->warning("requisition.{$field}", $message);
            }
        }
    }

    private function checkQuotations(Collection $quotations, CaseRequisition $requisition): void
    {
        $today = now(self::LOCAL_TIMEZONE)->toDateString();
        $hasRecommendation = false;
        $recommendedTotal = '0';

        foreach ($quotations as $i => $q) {
            $key = "quotations.{$i}";

            if ($q->lead_time_days === null) {
                $this->error("{$key}.lead_time_days", 'Enter the lead time in days.');
            }

            if ($q->recommended_quantity !== null) {
                if (Decimal::compare($q->recommended_quantity, $q->quantity_offered) > 0) {
                    $this->error("{$key}.recommended_quantity", 'The recommended quantity is more than the quantity offered.');
                } elseif ($q->minimum_order_quantity !== null
                    && Decimal::compare($q->recommended_quantity, $q->minimum_order_quantity) < 0) {
                    $this->error("{$key}.recommended_quantity", 'The recommended quantity is below the minimum order quantity.');
                }
            }

            if ($q->is_recommended && $q->recommended_quantity !== null && Decimal::compare($q->recommended_quantity, '0') > 0) {
                $hasRecommendation = true;
                $recommendedTotal = Decimal::add($recommendedTotal, $q->recommended_quantity);
            }

            if ($q->price_valid_until !== null && $q->price_valid_until->toDateString() < $today) {
                $this->warning("{$key}.price_valid_until", 'This price is no longer valid.');
            }

            if ($q->technical_status !== TechnicalStatus::PENDING) {
                $this->warning("{$key}.technical_status", 'MOH has already assessed this quotation.');
            }
        }

        if (! $hasRecommendation) {
            $this->error('quotations.recommended', 'Recommend at least one quotation with a quantity greater than zero.');
        } elseif ($requisition->quantity_requested !== null
            && Decimal::compare($recommendedTotal, $requisition->quantity_requested) !== 0) {
            $this->warning('quotations.recommended_total', 'The total recommended quantity differs from the quantity requested.');
        }
    }

    /** V2-5: the case's previous-request answer as saved (null = not answered). */
    public static function previousRequest(ProcurementCase $case): ?bool
    {
        $value = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->value('previous_request_in_cycle');

        return $value === null ? null : (bool) $value;
    }

    /** Rows ordered as saved, re-indexed from 0 so keys match the payload positions. */
    private function rows(string $model, string $caseId): Collection
    {
        return $model::withoutGlobalScopes()->where('case_id', $caseId)->orderBy('sort_order')->orderBy('id')->get()->values();
    }

    private function requirePresent(CaseRequisition $r, string $field, string $message): void
    {
        if ($this->blank($r->{$field})) {
            $this->error("requisition.{$field}", $message);
        }
    }

    private function requirePositive(CaseRequisition $r, string $field, string $message): void
    {
        if ($r->{$field} === null || Decimal::compare($r->{$field}, '0') <= 0) {
            $this->error("requisition.{$field}", $message);
        }
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function error(string $key, string $message): void
    {
        $this->errors[] = ['key' => $key, 'message' => $message];
    }

    private function warning(string $key, string $message): void
    {
        $this->warnings[] = ['key' => $key, 'message' => $message];
    }
}
