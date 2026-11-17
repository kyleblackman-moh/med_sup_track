<?php

namespace App\Http\Resources\Requests;

use App\Enums\TenderOutcome;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\ProcurementTypeDocument;
use App\Submissions\AdHocReadiness;
use App\Submissions\ReadinessReport;
use Illuminate\Support\Str;

/**
 * The "sections" prop of the AD_HOC form (slice F2): the server's readiness
 * grouped by form section, in form order. The page only shows it; only the
 * server decides what is complete.
 *
 * Each section: key, label, optional, complete, a one-line summary built from
 * server counts, and its issues. An issue carries the readiness key, the field
 * (the dotted key without the row index), the row's id when it is about a saved
 * row, and a plain-English message.
 *
 * Expects the case with procurementType, requisition and the child relations
 * loaded, in the same order SubmissionReadiness reads them.
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

    /** @return list<array{key: string, label: string, optional: bool, complete: bool, summary: string, issues: list<array<string, mixed>>}> */
    public static function for(ProcurementCase $case, ReadinessReport $report): array
    {
        $issues = array_fill_keys(array_keys(self::SECTIONS), []);
        foreach ($report->errors as $error) {
            $section = AdHocReadiness::sectionOf($error['key']);
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

    private static function tenderSummary(ProcurementCase $case): string
    {
        $outcome = $case->requisition?->tender_outcome;
        if ($outcome === null) {
            return filled($case->requisition?->tender_reference) ? 'Outcome not chosen' : 'Not started';
        }
        if ($outcome === TenderOutcome::NOT_AWARDED) {
            return 'Not awarded';
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
