<?php

namespace App\Http\Resources\Requests;

use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\ProcurementTypeDocument;
use App\Submissions\ReadinessReport;
use App\Submissions\RequestSections;
use Illuminate\Support\Str;

/**
 * The request form's readiness, grouped by section (the server decides; the
 * page only shows it). Expects the case with procurementType, requisition and
 * the child relations loaded, in the same order SubmissionReadiness reads them.
 *
 * Two shapes while the screen is rebuilt:
 *
 * - for(): the "sections" prop of the current screen (slice F2), in its six
 *   sections (item, tender, stock, quotations, price, documents). Kept until
 *   V2-2 so that screen does not break; V2-1 issues are mapped onto it.
 * - requestSections(): the "request_sections" prop (slice V2-1). The sections
 *   the type shows, in form order (RequestSections), each with whether it is
 *   optional for the type, its jump anchor, whether it is complete and its
 *   issues. Used by the V2-2 screen.
 *
 * An issue carries the readiness key, the field (the dotted key without the
 * row index), the row's id when it is about a saved row, and a plain-English
 * message.
 */
final class AdHocSections
{
    public const SECTIONS = [
        'item' => 'Item',
        'tender' => 'Tender background',
        'stock' => 'Stock',
        'quotations' => 'Quotations',
        'price' => 'Price comparison',
        'documents' => 'Documents',
    ];

    /** The current screen's sections: field => section (V2-1 fields included). */
    private const SCREEN_FIELDS = [
        'item' => ['request_type', 'item_description', 'strength', 'dosage_form', 'din', 'quantity_requested', 'unit_of_measure', 'clinical_note'],
        'tender' => [
            'tender_reference', 'tender_cycle_start', 'tender_cycle_end', 'tender_outcome', 'tender_issue_explanation',
            'tender_awards', 'purchase_orders', 'quantity_on_order', 'variation',
        ],
        'stock' => ['stock_location', 'average_monthly_consumption', 'no_stock_on_hand', 'stock_expiries'],
        'quotations' => ['rfq_audience', 'quotations', 'quantity_to_purchase'],
        'price' => ['tender_price', 'previous_price', 'quoted_price_acceptable', 'price_note'],
        'documents' => ['documents'],
    ];

    /** @return list<array{key: string, label: string, optional: bool, complete: bool, summary: string, issues: list<array<string, mixed>>}> */
    public static function for(ProcurementCase $case, ReadinessReport $report): array
    {
        $issues = array_fill_keys(array_keys(self::SECTIONS), []);
        foreach ($report->errors as $error) {
            $section = self::screenSectionOf($error['key']);
            if ($section !== null) {
                $issues[$section][] = self::issue($case, $error);
            }
        }

        $priceEntered = self::priceEntered($case);

        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => self::SECTIONS[$key],
            'optional' => $key === 'price',
            // The optional price section is complete only once something is entered.
            'complete' => $issues[$key] === [] && ($key !== 'price' || $priceEntered),
            'summary' => self::summary($key, $case, $priceEntered),
            'issues' => $issues[$key],
        ], array_keys(self::SECTIONS));
    }

    /**
     * Slice V2-1: the sections the case's type shows, in form order, with their
     * issues. Every error lands in a section (an unmapped key would be a bug).
     *
     * @return list<array{key: string, label: string, optional: bool, anchor: string, complete: bool, issues: list<array<string, mixed>>}>
     */
    public static function requestSections(ProcurementCase $case, ReadinessReport $report): array
    {
        $visible = RequestSections::visible((string) $case->procurementType?->code);
        $issues = array_fill_keys(array_column($visible, 'key'), []);

        foreach ($report->errors as $error) {
            $section = RequestSections::sectionOf($error['key']);
            if ($section !== null && array_key_exists($section, $issues)) {
                $issues[$section][] = self::issue($case, $error);
            }
        }

        return array_map(fn (array $section) => [
            ...$section,
            'anchor' => "section-{$section['key']}",
            'complete' => $issues[$section['key']] === [],
            'issues' => $issues[$section['key']],
        ], $visible);
    }

    private static function screenSectionOf(string $key): ?string
    {
        $parts = explode('.', $key);
        $field = $parts[0] === 'requisition' ? ($parts[1] ?? '') : $parts[0];

        foreach (self::SCREEN_FIELDS as $section => $fields) {
            if (in_array($field, $fields, true)) {
                return $section;
            }
        }

        return null;
    }

    /** @param  array{key: string, message: string}  $error */
    private static function issue(ProcurementCase $case, array $error): array
    {
        $parts = explode('.', $error['key']);
        $rowId = null;
        $field = $error['key'];

        // "<section>.<index>.<field>": point at the saved row by id, not by index.
        if (count($parts) === 3 && ctype_digit($parts[1])) {
            $relation = match ($parts[0]) {
                'tender_awards' => $case->tenderAwards,
                'purchase_orders' => $case->purchaseOrders,
                'stock_expiries' => $case->stockExpiries,
                'quotations' => $case->quotations,
                default => null,
            };
            $rowId = $relation?->values()->get((int) $parts[1])?->getKey();
            $field = "{$parts[0]}.{$parts[2]}";
        }

        return [
            'key' => $error['key'],
            'field' => $field,
            'row_id' => $rowId,
            'message' => $error['message'],
        ];
    }

    private static function summary(string $key, ProcurementCase $case, bool $priceEntered): string
    {
        $r = $case->requisition;

        return match ($key) {
            'item' => filled($r?->item_description) ? Str::limit((string) $r->item_description, 80) : 'Not started',
            'tender' => self::tenderSummary($case),
            'stock' => match (true) {
                (bool) $r?->no_stock_on_hand => 'No stock on hand',
                $case->stockExpiries->isNotEmpty() => self::count($case->stockExpiries->count(), 'batch', 'batches').', '.$case->batchStockOnHand().' on hand',
                $r?->average_monthly_consumption !== null || filled($r?->stock_location) => 'No batches yet',
                default => 'Not started',
            },
            'quotations' => $case->quotationsReceived() > 0
                ? self::count($case->quotationsReceived(), 'quotation').', '.$case->quotationsConsidered().' considered'
                : 'Not started',
            'price' => $priceEntered ? 'Added' : 'Not added',
            'documents' => self::documentsSummary($case),
        };
    }

    /** Slice V2-1: from the rows only; the tender outcome is no longer read. */
    private static function tenderSummary(ProcurementCase $case): string
    {
        if ($case->tenderAwards->isEmpty() && $case->purchaseOrders->isEmpty()) {
            return filled($case->requisition?->tender_reference) ? 'No awarded supplier yet' : 'Not started';
        }

        return self::count($case->suppliersAwarded(), 'supplier').' awarded, '.self::count($case->purchaseOrders->count(), 'order');
    }

    private static function documentsSummary(ProcurementCase $case): string
    {
        $purposes = ProcurementTypeDocument::where('procurement_type_id', $case->procurement_type_id)->pluck('purpose')
            ->map(fn ($p) => $p instanceof \BackedEnum ? $p->value : $p)->all();
        $files = Document::withoutGlobalScopes()->current()
            ->where('case_id', $case->getKey())->whereIn('purpose', $purposes)->count();

        return $files === 0 ? 'No files yet' : self::count($files, 'file');
    }

    private static function priceEntered(ProcurementCase $case): bool
    {
        $r = $case->requisition;

        return $r !== null && ($r->tender_price !== null || $r->previous_price !== null
            || $r->quoted_price_acceptable !== null || filled($r->price_note));
    }

    private static function count(int $n, string $singular, ?string $plural = null): string
    {
        return $n.' '.($n === 1 ? $singular : ($plural ?? $singular.'s'));
    }
}
