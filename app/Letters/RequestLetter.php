<?php

namespace App\Letters;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\DeliveryState;
use App\Enums\DocumentVisibility;
use App\Enums\ScanStatus;
use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Models\CaseVariationRow;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\ProcurementTypeDocument;
use App\Models\User;
use App\Submissions\ProcurementTypeProfile as Types;
use App\Submissions\RequestSections;
use App\Support\Audit;
use App\Support\Display;
use App\Support\NumberWords;
use App\Support\Qpdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The vendor's request letter (Kyle, 2026-11-24), in the layout and wording of
 * NIPDEC's paper letters, filled from the saved application. Every sentence and
 * figure is built here; the Blade template (letters/request) only lays it out.
 *
 * Three letter kinds: Tender Excess, Tender Variation, and Ad Hoc (the three
 * AD_HOC_* request types, and the old single AD_HOC form). Other types (the old
 * Pharm / Non-Pharm forms, NOT_SELECTED) have no letter: for() returns null.
 *
 * Nothing is typed into the letter. A value that was not entered leaves its
 * sentence or table out; there are no placeholders. Sections and fields the
 * type hides (RequestSections) are left out as the form leaves them out.
 *
 * Vendor-safe for every audience: the request as filled, the external clean
 * documents and the vendor's own sign-off. No internal notes, review outcomes,
 * CAB reasons, query messages or staff names. The CALLER authorises.
 *
 * The vendor's current external clean documents (all PDFs) follow the letter as
 * its pages, in upload order, through qpdf (Kyle, 2026-11-25); a draft's
 * appended pages carry the watermark too. A document qpdf cannot open, or every
 * document while qpdf is not installed, is listed under /Attach instead.
 */
final class RequestLetter
{
    public const TIMEZONE = 'America/Port_of_Spain';

    public const VENDOR = 'vendor';

    public const STAFF = 'staff';

    public const EXCESS = 'excess';

    public const VARIATION = 'variation';

    public const AD_HOC = 'ad_hoc';

    private const KINDS = [
        Types::TENDER_EXCESS => self::EXCESS,
        Types::TENDER_VARIATION => self::VARIATION,
        Types::AD_HOC_EMERGENCY => self::AD_HOC,
        Types::AD_HOC_TENDER_UNABLE => self::AD_HOC,
        Types::AD_HOC_NOT_AWARDED => self::AD_HOC,
        Types::AD_HOC => self::AD_HOC,
    ];

    public const DRAFT_WATERMARK = 'DRAFT – NOT SUBMITTED';

    /** The footer's division while the submitter has none (Kyle, 2026-11-25). */
    public const FOOTER_DIVISION = 'Pharmaceutical Division';

    /** @var list<string> Absolute paths of the documents pdf() appends; set by attachments(). */
    private array $appended = [];

    private function __construct(
        private readonly ProcurementCase $case,
        private readonly string $kind,
        private readonly CarbonImmutable $now,
    ) {}

    /** Null for a type with no letter. */
    public static function for(ProcurementCase $case, ?CarbonImmutable $now = null): ?self
    {
        // The caller has authorised this case; its own rows are read without the
        // viewer scope (VisibleViaCaseScope), so the letter is the same for every
        // audience and for a console run.
        $own = fn ($query) => $query->withoutGlobalScopes();
        $case->load([
            'procurementType', 'organisation',
            'requisition' => $own, 'tenderAwards' => $own, 'purchaseOrders' => $own, 'purchaseOrders.award',
            'quotations' => $own, 'stockExpiries' => $own, 'variationRows' => $own,
        ]);
        $kind = self::KINDS[$case->procurementType?->code] ?? null;

        return $kind === null ? null : new self($case, $kind, $now ?? CarbonImmutable::now('UTC'));
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /** The letter as HTML (what the PDF is rendered from; tests read this). */
    public function html(): string
    {
        return view('letters.request', ['letter' => $this->data()])->render();
    }

    /**
     * Renders the PDF bytes, with the running header on pages 2+, followed by
     * the appended documents. Should the merge itself fail, the letter alone is
     * returned (and the failure logged by Qpdf).
     */
    public function pdf(): string
    {
        $data = $this->data();
        $pdf = Pdf::loadHTML(view('letters.request', ['letter' => $data])->render())
            ->setPaper($this->paper(), 'portrait')
            ->setOption(['default_font' => 'Times-Roman', 'enable_remote' => false, 'enable_font_subsetting' => true]);

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        $header = $data['running_header'];
        $dompdf->getCanvas()->page_script(function (int $page, int $_pages, Canvas $canvas, FontMetrics $metrics) use ($header) {
            if ($page < 2) {
                return;
            }
            $canvas->set_opacity(1.0); // the draft watermark's opacity must not carry over
            $canvas->text(100, 28, "Page {$page} – {$header}", $metrics->getFont('Times-Roman'), 9);
        });

        $letter = $dompdf->output();
        if ($this->appended === []) {
            return $letter;
        }

        return Qpdf::append($letter, $this->appended, $data['draft'] ? $this->stamp() : null) ?? $letter;
    }

    /** A one-page PDF of the draft watermark alone, laid over each appended page. */
    private function stamp(): string
    {
        return Pdf::loadHTML(view('letters.stamp', ['watermark' => self::DRAFT_WATERMARK])->render())
            ->setPaper($this->paper(), 'portrait')
            ->setOption(['enable_remote' => false])
            ->output();
    }

    private function paper(): string
    {
        return (string) config('procurement.letter.paper', 'letter');
    }

    /** "{reference} – {item}.pdf"; a draft has no reference yet. */
    public function filename(): string
    {
        $reference = $this->case->reference_no ?? 'Draft';
        $name = trim($reference.' – '.$this->item());

        return trim(preg_replace('/[\\\\\/:*?"<>|\r\n]+/u', ' ', $name)).'.pdf';
    }

    /** Audits one export (the audience and whether it was a draft; no letter text). */
    public function recordExport(User $viewer, string $audience): void
    {
        Audit::record(
            AuditAction::CaseLetterExported,
            ['type' => 'case', 'id' => $this->case->getKey()],
            $this->case->getKey(),
            ['audience' => $audience, 'draft' => $this->isDraft(), 'kind' => $this->kind, 'attached' => count($this->appended)],
            $viewer,
        );
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $org = $this->case->organisation;
        $signer = $this->signer();

        return [
            'kind' => $this->kind,
            'draft' => $this->isDraft(),
            'watermark' => self::DRAFT_WATERMARK,
            'band_colour' => (string) config('procurement.letter.band_colour'),
            'letterhead' => [
                'title' => (string) ($org?->short_name ?: $org?->name),
                'subtitle' => $org?->short_name ? Str::upper((string) $org->name) : null,
                'logo' => null, // organisations.logo_path: the logo comes later
            ],
            'date' => $this->letterDate()->format('F j, Y'),
            'recipient' => $this->recipient(),
            'salutation' => (string) config('procurement.letter.salutation'),
            'subject' => $this->subject(),
            'opening' => $this->opening(),
            'award' => $this->award(),
            'orders' => $this->orders(),
            'issues' => $this->issues(),
            'clinical_note' => $this->text($this->req('clinical_note')),
            'variations' => $this->variations(),
            'stock' => $this->kind === self::VARIATION ? null : $this->stock(),
            'rfq' => $this->kind === self::VARIATION ? [] : $this->rfq(),
            'close' => [
                'organisation' => Str::upper((string) $org?->name),
                'name' => $this->text($signer?->name),
                'title' => $this->text($signer?->job_title),
                'division' => $this->text($signer?->division),
            ],
            'attachments' => $this->attachments(),
            'annex' => $this->kind === self::VARIATION ? null : $this->annex(),
            'running_header' => $this->itemWithDin(' – '),
            'footer' => implode(' | ', array_filter([
                (string) $org?->name,
                $this->text($signer?->division) ?? self::FOOTER_DIVISION,
                $this->contact(),
            ])),
        ];
    }

    private function isDraft(): bool
    {
        return $this->case->status === CaseStatus::DRAFT;
    }

    /** The submission date (Port of Spain); a draft uses today. */
    private function letterDate(): CarbonInterface
    {
        $date = $this->isDraft() ? null : $this->case->submitted_at;

        return CarbonImmutable::parse($date ?? $this->now)->setTimezone(self::TIMEZONE);
    }

    /** The submitter (the creator while it is a draft). Vendor users only: never a staff name. */
    private function signer(): ?User
    {
        $id = $this->case->submitted_by ?? $this->case->created_by;
        $user = $id === null ? null : User::query()->find($id);

        return $user !== null && $user->org_id === $this->case->org_id ? $user : null;
    }

    /** @return list<string> */
    private function recipient(): array
    {
        $r = (array) config('procurement.letter.recipient');

        return array_values(array_filter([
            $r['name'] ?? null,
            $r['title'] ?? null,
            $r['ministry'] ?? null,
            ...((array) ($r['address_lines'] ?? [])),
            $r['city'] ?? null,
        ], fn ($line) => is_string($line) && trim($line) !== ''));
    }

    private function subject(): string
    {
        return match ($this->kind) {
            self::EXCESS => 'Re: Request for the Approval to Purchase in Excess of Tender- '.$this->itemWithDin(' – '),
            self::VARIATION => 'Re: Request for Variation of Tender'.$this->variationPhrase().' for '.$this->itemWithDin(' – '),
            default => 'Re: Request for the Approval of an Ad Hoc Purchase of '.$this->itemWithDin(' - '),
        };
    }

    private function opening(): string
    {
        $quantity = $this->quantityPhrase($this->req('quantity_requested'));

        return match ($this->kind) {
            // No full stop: as the Tender Excess paper letter (Kyle, 2026-11-25).
            self::EXCESS => 'Your approval is sought to purchase in excess of tender '.$quantity.$this->itemWithDin(' – '),
            self::VARIATION => 'Your approval is sought for the Request for Variation of Tender'.$this->variationPhrase().' for '.$this->itemWithDin(' – '),
            default => 'Your approval is sought for the Ad Hoc Purchase of '.$quantity.$this->itemWithDin(' - ').'.',
        };
    }

    /** " – Change of Manufacturer and Country of Origin" from the variation rows' types; empty without any. */
    private function variationPhrase(): string
    {
        $types = $this->variationRows()
            ->map(fn (CaseVariationRow $r) => trim((string) $r->variation_type))
            ->filter()->unique(fn (string $t) => mb_strtolower($t))->values()->all();

        return $types === [] ? '' : ' – '.$this->joinList($types);
    }

    /** @return array{sentence: string, rows: list<array<string, string>>}|null */
    private function award(): ?array
    {
        $awards = $this->case->tenderAwards->filter(fn (CaseTenderAward $a) => trim((string) $a->supplier_name) !== '')->values();

        if ($awards->isEmpty()) {
            return null;
        }

        $n = $this->case->suppliersAwarded();
        $start = $this->req('tender_cycle_start');
        $end = $this->req('tender_cycle_end');
        $cycle = $start !== null && $end !== null ? $start->format('Y').'/'.$end->format('Y').' Tender' : 'Tender';

        return [
            'sentence' => "At the {$cycle}, ".$this->item().' was awarded to '.$this->counted($n, 'supplier', 'suppliers')
                .'. The details of this award are summarized in the table below:',
            'rows' => $awards->map(fn (CaseTenderAward $a) => [
                'supplier' => (string) $a->supplier_name,
                'manufacturer' => $this->text($a->manufacturer),
                'country' => $this->text($a->country_of_origin),
                'brand' => $this->text($a->brand),
                'quantity' => $this->withUnit($a->quantity_awarded), // Kyle, 2026-11-26: no pack size
                'percentage' => $a->percentage_awarded === null ? null : bcadd((string) $a->percentage_awarded, '0.5', 0).'%',
                'unit_cost' => $this->unitCost($a->unit_cost),
                'total' => $a->total() === null ? null : Display::money($a->total()),
            ])->all(),
        ];
    }

    /** @return array{sentence: string, rows: list<array<string, string|null>>}|null */
    private function orders(): ?array
    {
        if (! RequestSections::isVisible($this->code(), 'existing_arrangement')) {
            return null;
        }

        $orders = $this->case->purchaseOrders;
        if ($orders->isEmpty()) {
            return null;
        }

        $plural = $this->case->suppliersAwarded() > 1;

        return [
            'sentence' => 'Orders were placed with the awarded '.($plural ? 'suppliers' : 'supplier').'. The details are summarized in the table below:',
            'rows' => $orders->map(fn (CasePurchaseOrder $o) => [
                'supplier' => $this->text($o->award?->supplier_name ?? $o->supplier_name),
                'po' => $this->text($o->po_number),
                'ordered' => $this->withUnit($o->quantity_ordered),
                'ordered_on' => $o->ordered_on?->format('F j, Y'),
                'delivered' => $this->withUnit($o->quantity_delivered),
                'outstanding' => $this->withUnit($o->quantityOutstanding()),
                'delivery' => $this->deliveryCell($o),
            ])->all(),
        ];
    }

    /**
     * Kyle, 2026-11-25: Other prints what the vendor wrote for it (e.g. "Variation of tender
     * in progress"); "Delivered" (with the date) when delivered; else the status label. The
     * delivery note is now its number (Delivery Note No.), so it is not a status.
     */
    private function deliveryCell(CasePurchaseOrder $o): ?string
    {
        if ($o->delivery_state === DeliveryState::OTHER) {
            return $this->text($o->delivery_status) ?? $o->delivery_state->label();
        }

        if ($o->delivery_state === DeliveryState::FULLY_DELIVERED) {
            return $o->delivery_date === null ? 'Delivered' : 'Delivered '.$o->delivery_date->format('F j, Y');
        }

        return $o->delivery_state?->label();
    }

    private function issues(): ?string
    {
        if (in_array('tender_issue_explanation', RequestSections::hiddenFields($this->code()), true)) {
            return null;
        }

        return $this->text($this->req('tender_issue_explanation'));
    }

    /** @return list<array<string, string|null>>|null Tender Variation only, when the variation details are shown. */
    private function variations(): ?array
    {
        if ($this->kind !== self::VARIATION) {
            return null;
        }

        $rows = $this->variationRows()->map(fn (CaseVariationRow $r) => [
            'type' => $this->text($r->variation_type),
            'existing' => $this->text($r->existing_detail),
            'proposed' => $this->text($r->proposed_detail),
            'effective' => $this->text($r->effective_detail),
        ])->values()->all();

        return $rows === [] ? null : $rows;
    }

    /** @return array{sentences: list<string>, expiries: list<array{quantity: string|null, expiry: string}>, with_quantity: bool}|null */
    private function stock(): ?array
    {
        $r = $this->case->requisition;
        if ($r === null) {
            return null;
        }

        $location = $this->text($r->stock_location);
        $at = $location === null ? '' : ' at '.$location;
        $sentences = [];

        if ($r->no_stock_on_hand) {
            $sentences[] = "At present, there is no stock on hand{$at}.";
        } elseif ($r->quantity_on_hand !== null) {
            $sentences[] = 'At present, there are '.$this->quantityWords((string) $r->quantity_on_hand)." in stock{$at}.";
        }

        if ($r->average_monthly_consumption !== null) {
            $sentences[] = 'The average monthly consumption is approximately '.Display::quantity((string) $r->average_monthly_consumption)
                .$this->unitSuffix().' per month.';
        }

        $expiries = $r->no_stock_on_hand ? collect() : $this->case->stockExpiries->filter(fn (CaseStockExpiry $e) => $e->expiry_date !== null)->values();

        if ($sentences === [] && $expiries->isEmpty()) {
            return null;
        }

        return [
            'sentences' => $sentences,
            'with_quantity' => $expiries->contains(fn (CaseStockExpiry $e) => $e->quantity !== null),
            'expiries' => $expiries->map(fn (CaseStockExpiry $e) => [
                'quantity' => $e->quantity === null ? null : $this->withUnit((string) $e->quantity),
                'expiry' => $e->expiry_date->format('F j, Y'),
            ])->all(),
        ];
    }

    /**
     * The RFQ paragraph, each not-considered quotation's reason, the
     * recommendation, the price comparison (Tender Excess) and the lead time.
     *
     * @return list<string>
     */
    private function rfq(): array
    {
        $received = $this->receivedQuotations();
        $paragraphs = [];

        if ($received->isNotEmpty()) {
            $audience = $this->req('rfq_audience');
            $issued = $audience === null ? 'was issued' : 'was issued to '.mb_strtolower($audience->label());
            $n = $received->count();
            $paragraphs[] = "To supply the system with stock, a Request for Quotations (RFQ) {$issued}, and "
                .$this->counted($n, 'quotation', 'quotations').($n === 1 ? ' was' : ' were')
                .' received. The details are summarised in the quotation comparison sheet.';
        }

        foreach ($received->reject(fn (CaseQuotation $q) => $q->considered) as $q) {
            $reason = $this->text($q->reason_accepted_excluded);
            $paragraphs[] = 'The quotation from '.$q->supplier_name.' was not considered'
                .($reason === null ? '.' : ': '.$this->sentence($reason));
        }

        $recommended = $this->case->recommendedQuotation();
        if ($recommended !== null) {
            $paragraphs[] = $this->recommendation($recommended);

            // Kyle, 2026-11-26: the vendor's Reason for Justification, as written.
            if (($why = $this->text($recommended->reason_accepted_excluded)) !== null) {
                $paragraphs[] = 'Justification for the recommendation: '.$this->sentence($why);
            }

            if ($this->kind === self::EXCESS && ($comparison = $this->priceComparison()) !== null) {
                $paragraphs[] = $comparison;
            }

            if (($lead = $this->leadTime($recommended)) !== null) {
                $paragraphs[] = $lead;
            }
        }

        return $paragraphs;
    }

    private function recommendation(CaseQuotation $q): string
    {
        $item = $this->itemWithDin(' – ');
        $supplier = (string) $q->supplier_name;
        $unit = $q->unit_price === null ? null : Display::price((string) $q->unit_price);
        $total = $q->totalPrice() === null ? null : Display::money($q->totalPrice());

        if ($this->kind === self::EXCESS) {
            $quantity = $q->quantity_offered === null ? '' : Display::quantity((string) $q->quantity_offered).$this->unitSuffix().' of ';
            $costs = $this->joinList(array_values(array_filter([
                $unit === null ? null : "a unit cost of {$unit}",
                $total === null ? null : "a total cost of {$total}",
            ])));

            // The Tender Excess template's wording (Kyle, 2026-11-25).
            return 'To mitigate the risk of a stock-out and ensure continuity of supply, it is recommended that approval be granted to purchase '
                ."{$quantity}{$item} from {$supplier}".($costs === '' ? '' : " at {$costs}").($total === null ? '' : ' (VAT inclusive)').'.';
        }

        // The Ad Hoc template's wording (Kyle, 2026-11-25).
        return 'Once the quotation fulfils the requirements of the Ministry of Health, it is recommended to procure '
            .$this->quantityPhrase($q->quantity_offered === null ? null : (string) $q->quantity_offered)
            ."{$item} from {$supplier}".($total === null ? '' : " at a total cost of {$total} (VAT inclusive)").'.'
            .($unit === null ? '' : " The unit cost is {$unit}.");
    }

    /** Tender Excess: the tender and previous prices the vendor entered. */
    private function priceComparison(): ?string
    {
        $hidden = RequestSections::hiddenFields($this->code());
        $tender = in_array('tender_price', $hidden, true) ? null : $this->req('tender_price');
        $previous = in_array('previous_price', $hidden, true) ? null : $this->req('previous_price');

        $parts = array_values(array_filter([
            $tender === null ? null : 'the tender unit cost was '.Display::price((string) $tender),
            $previous === null ? null : 'the previous purchase unit cost was '.Display::price((string) $previous),
        ]));

        return $parts === [] ? null : 'For comparison, '.$this->joinList($parts).'.';
    }

    /** The templates' lead time sentence (Kyle, 2026-11-25). */
    private function leadTime(CaseQuotation $q): ?string
    {
        if ($q->lead_time_days === null) {
            return null;
        }

        return 'The anticipated lead time for this request is '.$this->counted((int) $q->lead_time_days, 'day', 'days').'.';
    }

    /** Ex Stock: the quantity available from stock (Kyle, 2026-11-24); an older row's Yes/No. */
    private function exStock(CaseQuotation $q): ?string
    {
        if ($q->quantity_available_now !== null) {
            return $this->withUnit((string) $q->quantity_available_now);
        }

        return $q->ex_stock === null ? null : ($q->ex_stock ? 'Yes' : 'No');
    }

    /** @return list<array<string, string|null>>|null The Quotation Comparison Sheet (Ad Hoc, Tender Excess). */
    private function annex(): ?array
    {
        $rows = $this->receivedQuotations()->map(fn (CaseQuotation $q) => [
            'supplier' => (string) $q->supplier_name,
            'quantity' => $q->quantity_offered === null ? null : Display::quantity((string) $q->quantity_offered),
            'unit_price' => $q->unit_price === null ? null : Display::price((string) $q->unit_price),
            'subtotal' => $q->subtotal() === null ? null : Display::money($q->subtotal()),
            'vat' => $q->vatAmount() === null ? null : Display::money($q->vatAmount()),
            'total' => $q->totalPrice() === null ? null : Display::money($q->totalPrice()),
            'ex_stock' => $this->exStock($q),
            'lead_time' => $q->lead_time_days === null ? null : $q->lead_time_days.' '.($q->lead_time_days === 1 ? 'day' : 'days'),
            'brand' => $this->text($q->brand),
            'manufacturer' => $this->text($q->manufacturer),
            'country' => $this->text($q->country_of_origin),
            'remarks' => $this->text($q->remarks),
            // Considered / Recommended in one column, so the sheet fits the page.
            'status' => match (true) {
                $q->is_recommended => 'Recommended',
                $q->considered => 'Considered',
                default => 'Not considered',
            },
        ])->values()->all();

        return $rows === [] ? null : $rows;
    }

    /**
     * External, clean, current documents. Those qpdf can open are queued for
     * pdf() to append ($this->appended) and are not listed; the rest are listed
     * under /Attach (file name and category), as the printed form lists them.
     *
     * @return list<array{title: string, category: string}>
     */
    private function attachments(): array
    {
        $labels = ProcurementTypeDocument::where('procurement_type_id', $this->case->procurement_type_id)
            ->get(['purpose', 'label'])
            ->mapWithKeys(fn (ProcurementTypeDocument $d) => [$d->purpose->value => $d->label]);
        $disk = Storage::disk('documents');

        $this->appended = [];
        $listed = [];
        $documents = Document::withoutGlobalScopes()->current()
            ->where('case_id', $this->case->getKey())
            ->where('visibility', DocumentVisibility::EXTERNAL->value)
            ->where('scan_status', ScanStatus::CLEAN->value)
            ->orderBy('uploaded_at')->orderBy('id')
            ->get();

        foreach ($documents as $document) {
            $path = $disk->path((string) $document->storage_key);
            if (Qpdf::pages($path) > 0) {
                $this->appended[] = $path;

                continue;
            }
            $listed[] = [
                'title' => (string) $document->original_name,
                'category' => $labels[$document->purpose->value] ?? Str::headline($document->purpose->value),
            ];
        }

        return $listed;
    }

    private function contact(): ?string
    {
        $org = $this->case->organisation;
        $parts = array_values(array_filter([
            $this->text($org?->address === null ? null : preg_replace('/\s*\R\s*/', ', ', (string) $org->address)),
            $this->text($org?->phone),
            $this->text($org?->email),
        ]));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /* ---- helpers ---- */

    private function code(): string
    {
        return (string) $this->case->procurementType?->code;
    }

    private function req(string $field): mixed
    {
        return $this->case->requisition?->{$field};
    }

    /** Variation rows, only while the form shows them (previous-request answer Yes). */
    private function variationRows()
    {
        return RequestSections::isVisible($this->code(), 'variation_details', $this->case->previous_request_in_cycle)
            ? $this->case->variationRows
            : collect();
    }

    private function receivedQuotations()
    {
        return $this->case->quotations->filter(fn (CaseQuotation $q) => trim((string) $q->supplier_name) !== '')->values();
    }

    /**
     * Description + strength + dosage form as entered. A part the description
     * already contains is not repeated.
     */
    private function item(): string
    {
        $description = trim((string) $this->req('item_description'));
        $parts = [$description];

        foreach (['strength', 'dosage_form'] as $field) {
            $part = trim((string) $this->req($field));
            if ($part !== '' && ! Str::contains($description, $part, ignoreCase: true)) {
                $parts[] = $part;
            }
        }

        return implode(' ', array_filter($parts, fn (string $p) => $p !== ''));
    }

    private function itemWithDin(string $dash): string
    {
        $din = trim((string) $this->req('din'));

        return $this->item().($din === '' ? '' : "{$dash}DIN {$din}");
    }

    /** "twenty-nine thousand nine hundred and eighty-eight (29,988) each of ", or "" without a quantity. */
    private function quantityPhrase(?string $quantity): string
    {
        $words = $this->quantityWords($quantity);

        return $words === null ? '' : $words.' of ';
    }

    /** "twenty-nine thousand nine hundred and eighty-eight (29,988) each"; null without a quantity. */
    private function quantityWords(?string $quantity): ?string
    {
        $words = NumberWords::withFigures($quantity);

        return $words === null ? null : $words.$this->unitSuffix();
    }

    /** " each": the unit of measure, lowercase, as running text uses it. */
    private function unitSuffix(): string
    {
        $unit = $this->text($this->req('unit_of_measure'));

        return $unit === null ? '' : ' '.mb_strtolower($unit);
    }

    /**
     * "60,000 Each" in the tables, with the unit of measure; "0" stays bare, as
     * the paper letters show it. Kyle, 2026-11-26: no pack size any more.
     */
    private function withUnit(?string $quantity): ?string
    {
        if ($quantity === null || ! is_numeric($quantity)) {
            return null;
        }

        $figures = Display::quantity($quantity);
        $unit = $this->text($this->req('unit_of_measure'));

        return $figures === '0' || $unit === null ? $figures : "{$figures} {$unit}";
    }

    /** "$95.00 Each", or "$368.20 per Box of 28 Tablets" for any other unit. */
    private function unitCost(?string $price): ?string
    {
        if ($price === null) {
            return null;
        }

        $unit = $this->text($this->req('unit_of_measure'));
        $figures = Display::price($price);

        return match (true) {
            $unit === null => $figures,
            mb_strtolower($unit) === 'each' => "{$figures} {$unit}",
            default => "{$figures} per {$unit}",
        };
    }

    /** "one (1) supplier", "two (2) suppliers". */
    private function counted(int $n, string $singular, string $plural): string
    {
        return NumberWords::spell($n).' ('.number_format($n).') '.($n === 1 ? $singular : $plural);
    }

    /** @param  list<string>  $items  "a", "a and b", "a, b and c" */
    private function joinList(array $items): string
    {
        if (count($items) < 2) {
            return $items[0] ?? '';
        }

        return implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }

    private function sentence(string $text): string
    {
        return preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
