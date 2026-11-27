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
use App\Support\Decimal;
use App\Support\Display;
use App\Support\NumberWords;
use App\Support\Qpdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The vendor's request letter, filled from the saved application. Every
 * sentence and figure is built here; the Blade templates (letters/request,
 * letters/annex) only lay it out.
 *
 * Kyle, 2026-11-27: the new section order (the "Reorganised Rivaroxaban
 * Approval Letter"), for every letter kind:
 *   1 Purpose of Request, 2 Current Stock Position, 3 Procurement Background
 *   (3.1 awards, 3.2 original tender orders, 3.3 approved tender excesses),
 *   4 Clinical Importance, 5 Request for Quotations and Evaluation (Tender
 *   Variation: Variation Requested), 6 Recommendation and Approval Requested;
 *   then the Quotation Comparison annex on a landscape page.
 * Sections and subsections are numbered in the order shown; one with nothing
 * entered is left out and the rest close up.
 *
 * Three letter kinds: Tender Excess, Tender Variation, and Ad Hoc (the three
 * AD_HOC_* request types, and the old single AD_HOC form). Other types (the old
 * Pharm / Non-Pharm forms, NOT_SELECTED) have no letter: for() returns null.
 *
 * Nothing is typed into the letter. A value that was not entered leaves its
 * sentence, row or section out; there are no placeholders.
 *
 * Vendor-safe for every audience: the request as filled, the external clean
 * documents and the vendor's own sign-off. No internal notes, review outcomes,
 * CAB reasons, query messages or staff names. The CALLER authorises.
 *
 * The vendor's current external clean documents (all PDFs) follow the letter
 * (and its annex) as pages, in upload order, through qpdf; a draft's appended
 * pages carry the watermark too. A document qpdf cannot open, or every document
 * while qpdf is not installed, is listed under /Attach instead. Without qpdf
 * the annex is a portrait page of the letter itself.
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

    /** Section 1: what approval is sought for, per kind. */
    private const TYPE_PHRASES = [
        self::AD_HOC => 'the ad hoc purchase',
        self::EXCESS => 'the purchase in excess of tender',
        self::VARIATION => 'a variation of tender',
    ];

    /** Section 6: "approval is requested for the following …". */
    private const TYPE_NOUNS = [
        self::AD_HOC => 'ad hoc purchase',
        self::EXCESS => 'purchase in excess of tender',
        self::VARIATION => 'variation of tender',
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
            'requisition' => $own, 'tenderAwards' => $own,
            'purchaseOrders' => $own, 'purchaseOrders.award', 'previousOrders' => $own, 'previousOrders.award',
            'quotations' => $own, 'stockExpiries' => $own, 'variationRows' => $own,
        ]);
        $kind = self::KINDS[$case->procurementType?->code] ?? null;

        return $kind === null ? null : new self($case, $kind, $now ?? CarbonImmutable::now('UTC'));
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * The letter as HTML, with the annex as its last (portrait) page: what
     * tests and the sample files read. pdf() renders the annex landscape.
     */
    public function html(): string
    {
        return view('letters.request', ['letter' => $this->data(), 'annexInline' => true])->render();
    }

    /**
     * Renders the PDF bytes, with the running header on pages 2+: the letter,
     * then the Quotation Comparison annex on landscape pages, then the appended
     * documents. Without qpdf (or should a merge fail) the annex stays a
     * portrait page of the letter, and the documents are not appended.
     */
    public function pdf(): string
    {
        $data = $this->data();
        $separateAnnex = $data['annex'] !== null && Qpdf::available();

        $dompdf = $this->render(view('letters.request', ['letter' => $data, 'annexInline' => ! $separateAnnex])->render(), 'portrait', 100);
        $letter = $dompdf->output();

        if ($separateAnnex) {
            $pages = $dompdf->getCanvas()->get_page_count();
            $annex = $this->render(view('letters.annex', ['letter' => $data])->render(), 'landscape', 54, $pages)->output();
            $merged = Qpdf::concat([$letter, $annex]);

            // The merge failed: the letter again, with the annex as its own portrait page.
            $letter = $merged ?? $this->render(view('letters.request', ['letter' => $data, 'annexInline' => true])->render(), 'portrait', 100)->output();
        }

        if ($this->appended === []) {
            return $letter;
        }

        return Qpdf::append($letter, $this->appended, $data['draft'] ? $this->stamp() : null) ?? $letter;
    }

    /** One rendered dompdf document with the running header from page 2 of the whole letter ($offset pages before it). */
    private function render(string $html, string $orientation, int $headerX, int $offset = 0): Dompdf
    {
        $dompdf = Pdf::loadHTML($html)
            ->setPaper($this->paper(), $orientation)
            ->setOption(['default_font' => 'Times-Roman', 'enable_remote' => false, 'enable_font_subsetting' => true])
            ->getDomPDF();
        $dompdf->render();

        $header = $this->itemWithDin(' – ');
        $dompdf->getCanvas()->page_script(function (int $page, int $_pages, Canvas $canvas, FontMetrics $metrics) use ($header, $headerX, $offset) {
            $number = $page + $offset;
            if ($number < 2) {
                return;
            }
            $canvas->set_opacity(1.0); // the draft watermark's opacity must not carry over
            $canvas->text($headerX, 28, "Page {$number} – {$header}", $metrics->getFont('Times-Roman'), 9);
        });

        return $dompdf;
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
            'sections' => $this->sections(),
            'close' => [
                'organisation' => Str::upper((string) $org?->name),
                'name' => $this->text($signer?->name),
                'title' => $this->text($signer?->job_title),
                'division' => $this->text($signer?->division),
            ],
            'attachments' => $this->attachments(),
            'annex' => $this->annex(),
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
            self::VARIATION => 'Re: Request for Variation of Tender'.$this->variationPhrase(' – ').' for '.$this->itemWithDin(' – '),
            default => 'Re: Request for the Approval of an Ad Hoc Purchase of '.$this->itemWithDin(' - '),
        };
    }

    /* ---- the numbered sections ---- */

    /**
     * The sections shown, numbered in order. Each is ['number', 'title',
     * 'blocks']; a block is a paragraph, a table, a numbered list or a
     * subsection (['type' => 'subsection', 'number', 'title', 'blocks']).
     *
     * @return list<array{number: string, title: string, blocks: list<array<string, mixed>>}>
     */
    private function sections(): array
    {
        $built = [
            ['Purpose of Request', $this->purpose()],
            ['Current Stock Position', $this->stock()],
            ['Procurement Background', $this->background()],
            ['Clinical Importance of '.$this->shortItem(), $this->clinical()],
            $this->kind === self::VARIATION
                ? ['Variation Requested', $this->variationRequested()]
                : ['Request for Quotations and Evaluation', $this->rfq()],
            ['Recommendation and Approval Requested', $this->recommendation()],
        ];

        $sections = [];
        foreach ($built as [$title, $blocks]) {
            if ($blocks === []) {
                continue;
            }
            $number = (string) (count($sections) + 1);
            $sub = 0;
            foreach ($blocks as $i => $block) {
                if ($block['type'] === 'subsection') {
                    $blocks[$i]['number'] = $number.'.'.(++$sub); // only the subsections shown
                }
            }
            $sections[] = ['number' => $number, 'title' => $title, 'blocks' => $blocks];
        }

        return $sections;
    }

    /** 1: the approval sought, then the vendor's Purpose of Request. */
    private function purpose(): array
    {
        $org = $this->case->organisation;
        $name = trim((string) $org?->name);
        $short = trim((string) $org?->short_name);
        $who = ($name === '' ? 'We' : 'The '.$name.($short === '' ? '' : " ({$short})"));

        $recommended = $this->case->recommendedQuotation();
        $quantity = $this->quantityWords($this->purchaseQuantity());
        $din = $this->text($this->req('din'));
        $type = self::TYPE_PHRASES[$this->kind].($this->kind === self::VARIATION ? $this->variationPhrase(' (', ')') : '');

        $sentence = $who.' seeks the Ministry of Health’s approval for '.$type
            .' of '.($quantity === null ? '' : $quantity.' of ').$this->item()
            .($din === null ? '' : " (DIN {$din})");

        if ($recommended !== null) {
            $total = $recommended->totalPrice();
            $sentence .= ' from '.$recommended->supplier_name.($total === null ? '' : ' at a total cost of '.Display::money($total));
        }

        return [
            $this->paragraph($sentence.'.'),
            ...array_map(fn (string $p) => $this->paragraph($p), $this->paragraphs($this->req('purpose_of_request'))),
        ];
    }

    /** 2: nil stock, or the quantity in stock with its expiry table; the consumption either way. */
    private function stock(): array
    {
        $r = $this->case->requisition;
        if ($r === null) {
            return [];
        }

        $at = ($location = $this->text($r->stock_location)) === null ? '' : ' at '.$location;
        $amc = $this->consumption();
        $consumption = $amc === null ? '' : ', with an average monthly consumption of approximately '.$amc;

        return match ($this->stockPosition()) {
            'nil' => [$this->paragraph('At present, there is nil stock of '.$this->item().$at.$consumption.'.')],
            'current' => [
                $this->paragraph('At present, there are '.$this->quantityWords((string) $r->quantity_on_hand)." in stock{$at}{$consumption}."),
                ...$this->expiryTable(),
            ],
            default => $amc === null ? [] : [$this->paragraph("The average monthly consumption is approximately {$amc}.")],
        };
    }

    /** @return list<array<string, mixed>> The stock items' expiry dates (with the drug name or quantity where entered). */
    private function expiryTable(): array
    {
        $items = $this->case->stockExpiries->filter(fn (CaseStockExpiry $e) => $e->expiry_date !== null)->values();
        if ($items->isEmpty()) {
            return [];
        }

        $withName = $items->contains(fn (CaseStockExpiry $e) => $this->text($e->item_name) !== null);
        $withQuantity = $items->contains(fn (CaseStockExpiry $e) => $e->quantity !== null);

        return [$this->table(
            array_values(array_filter([$withName ? 'Item' : null, $withQuantity ? 'Quantity' : null, 'Expiry Date'])),
            $items->map(fn (CaseStockExpiry $e) => array_values(array_filter([
                $withName ? ($this->text($e->item_name) ?? '') : null,
                $withQuantity ? ($this->withUnit($e->quantity === null ? null : (string) $e->quantity) ?? '') : null,
                $e->expiry_date->format('F j, Y'),
            ], fn ($cell) => $cell !== null)))->all(),
            'narrow',
        )];
    }

    /** 3: the original awards, their orders, the approved tender excesses, and the issues text. */
    private function background(): array
    {
        $subsections = array_values(array_filter([
            $this->awards(),
            $this->orderSubsection($this->case->purchaseOrders, 'Purchase Orders Issued Under the Original Tender', function (Collection $orders) {
                $n = $this->case->suppliersAwarded();

                return 'Purchase orders were subsequently issued to the awarded '.($n === 1 ? 'supplier' : 'suppliers').'. '
                    .($this->allDelivered($orders)
                        ? 'All quantities ordered under the original tender were delivered, as detailed below.'
                        : 'The details are outlined below.');
            }),
            $this->showsPreviousOrders() ? $this->orderSubsection($this->case->previousOrders, 'Approved Tender Excesses and Delivery Status', function (Collection $orders) {
                $n = $orders->count();

                return 'To maintain a continuous supply of '.$this->item().' to the public health system, '
                    .$this->counted($n, 'tender excess', 'tender excesses').($n === 1 ? ' was' : ' were')
                    .' submitted to and subsequently approved by the Ministry of Health. The corresponding purchase orders and delivery statuses are outlined below.';
            }) : null,
        ])); // numbered by sections()

        // Kept from the old letter (2026-11-25): the vendor's Issues Affecting the Tender Qty, after the tables.
        $issues = $this->text($this->req('tender_issue_explanation'));

        return [...$subsections, ...($issues === null ? [] : [$this->paragraph($issues)])];
    }

    /** 3.1, or null without an award row. */
    private function awards(): ?array
    {
        $awards = $this->case->tenderAwards->filter(fn (CaseTenderAward $a) => trim((string) $a->supplier_name) !== '')->values();
        if ($awards->isEmpty()) {
            return null;
        }

        $start = $this->req('tender_cycle_start');
        $end = $this->req('tender_cycle_end');
        $years = $start !== null && $end !== null ? $start->format('Y').'–'.$end->format('Y') : null;
        $n = $this->case->suppliersAwarded();

        return $this->subsection(
            'Original Tender Awards'.($years === null ? '' : " ({$years})"),
            [
                $this->paragraph('Under the '.($years === null ? 'original' : $years).' tender, '.$this->item().' was awarded to '
                    .$this->counted($n, 'supplier', 'suppliers').'. The details are outlined below.'),
                $this->table(
                    ['Supplier', 'Manufacturer (Country)', 'Brand', 'Quantity Awarded', '% Awarded', 'Unit Cost', 'Total Cost'],
                    $awards->map(fn (CaseTenderAward $a) => [
                        (string) $a->supplier_name,
                        $this->manufacturer($a->manufacturer, $a->country_of_origin),
                        $this->text($a->brand) ?? '',
                        $this->withUnit($a->quantity_awarded, $a->pack_size) ?? '',
                        $a->percentage_awarded === null ? '' : bcadd((string) $a->percentage_awarded, '0.5', 0).'%',
                        $this->unitCost($a->unit_cost, $a->pack_size) ?? '',
                        $a->total() === null ? '' : Display::money($a->total()),
                    ])->all(),
                ),
            ],
        );
    }

    /**
     * 3.2 and 3.3: one order table, or null without an order row.
     *
     * @param  Collection<int, CasePurchaseOrder>  $orders
     * @param  callable(Collection<int, CasePurchaseOrder>): string  $sentence
     */
    private function orderSubsection(Collection $orders, string $title, callable $sentence): ?array
    {
        if ($orders->isEmpty()) {
            return null;
        }

        return $this->subsection($title, [
            $this->paragraph($sentence($orders)),
            $this->table(
                ['Supplier', 'PO No.', 'Quantity Ordered', 'Date Ordered', 'Quantity Delivered', 'Outstanding', 'Delivery Status'],
                $orders->map(function (CasePurchaseOrder $o) {
                    $pack = $this->text($o->pack_size) ?? $this->text($o->award?->pack_size);

                    return [
                        $this->text($o->award?->supplier_name ?? $o->supplier_name) ?? '',
                        $this->text($o->po_number) ?? '',
                        $this->withUnit($o->quantity_ordered, $pack) ?? '',
                        $o->ordered_on?->format('F j, Y') ?? '',
                        $this->withUnit($o->quantity_delivered, $pack) ?? '',
                        $this->withUnit($o->quantityOutstanding(), $pack) ?? '',
                        $this->deliveryCell($o) ?? '',
                    ];
                })->all(),
            ),
        ]);
    }

    /** Every order delivered in full (nothing outstanding). */
    private function allDelivered(Collection $orders): bool
    {
        return $orders->every(fn (CasePurchaseOrder $o) => $o->quantityOutstanding() !== null && Decimal::compare($o->quantityOutstanding(), '0') <= 0);
    }

    /**
     * The status label ("Delivered", "Not Delivered"); Other prints what the vendor
     * wrote for it (e.g. "No confirmed ETA provided by supplier"). The delivery note
     * is its number (Delivery Note No., 2026-11-25), so it is not printed as a status.
     */
    private function deliveryCell(CasePurchaseOrder $o): ?string
    {
        if ($o->delivery_state === DeliveryState::OTHER) {
            return $this->text($o->delivery_status) ?? $o->delivery_state->label();
        }

        return $o->delivery_state?->label() ?? $this->text($o->delivery_status);
    }

    /** 4: "{item} is indicated for the following:" and the clinical note, one numbered item per line. */
    private function clinical(): array
    {
        $lines = array_values(array_filter(array_map(
            fn (string $line) => trim((string) preg_replace('/^\s*(?:\d+[.)]|[-•*])\s*/u', '', $line)),
            preg_split('/\R/u', (string) $this->req('clinical_note')) ?: [],
        ), fn (string $line) => $line !== ''));

        if ($lines === []) {
            return [];
        }

        return [
            $this->paragraph($this->shortItem().' is indicated for the following:'),
            ['type' => 'list', 'items' => $lines],
        ];
    }

    /** 5 (Ad Hoc, Tender Excess): the RFQ, the quotations not considered and the recommendation. Needs a quotation. */
    private function rfq(): array
    {
        $received = $this->receivedQuotations();
        if ($received->isEmpty()) {
            return [];
        }

        $audience = $this->req('rfq_audience');
        $n = $received->count();
        $blocks = [$this->paragraph(
            'Given the current stock position, a Request for Quotations (RFQ) was issued'
            .($audience === null ? '' : ' to '.mb_strtolower($audience->label()))
            .' to provide the system with stock. '
            .Str::ucfirst($this->counted($n, 'quotation', 'quotations')).($n === 1 ? ' was' : ' were')
            .' received, with the details presented in the attached comparison sheet.'
        )];

        // The quotations not considered, grouped by reason; a bid is its row's place in the comparison sheet.
        $groups = [];
        foreach ($received as $i => $q) {
            if ($q->considered) {
                continue;
            }
            $reason = rtrim((string) $this->text($q->reason_accepted_excluded), '.');
            $groups[mb_strtolower($reason)] ??= ['reason' => $reason, 'bids' => []];
            $groups[mb_strtolower($reason)]['bids'][] = (string) ($i + 1);
        }
        foreach (array_values($groups) as $g => ['reason' => $reason, 'bids' => $bids]) {
            $one = count($bids) === 1;
            $blocks[] = $this->paragraph(
                ($g === 0 ? 'Following consideration of the quotations, ' : '')
                .($one ? 'Bid ' : 'Bids ').$this->joinList($bids).($one ? ' was' : ' were').' not considered'
                .($reason === '' ? '' : ' due to '.$reason).'.'
            );
        }

        $recommended = $this->case->recommendedQuotation();
        if ($recommended !== null) {
            $basis = $this->text($this->req('basis_of_recommendation'));
            $blocks[] = $this->paragraph(
                ($basis === null ? 'Subject' : 'Based on '.rtrim($basis, '.').', and subject')
                .' to the quotation fulfilling the requirements of the Ministry of Health, the proposed purchase is recommended from '
                .$recommended->supplier_name.'.'
            );
        }

        return $blocks;
    }

    /** 5 (Tender Variation): the variation details as entered. */
    private function variationRequested(): array
    {
        $rows = $this->variationRows()->map(fn (CaseVariationRow $r) => [
            $this->text($r->variation_type) ?? '',
            $this->text($r->existing_detail) ?? '',
            $this->text($r->proposed_detail) ?? '',
            $this->text($r->effective_detail) ?? '',
        ])->values()->all();

        return $rows === [] ? [] : [$this->table(['Variation Type', 'Existing Approved Detail', 'Proposed Revised Detail', 'Effective Date'], $rows)];
    }

    /** 6: the lead-in built from the stock, consumption and outstanding order note, then the Description / Details table. */
    private function recommendation(): array
    {
        $at = ($location = $this->text($this->req('stock_location'))) === null ? '' : ' at '.$location;
        $amc = $this->consumption();
        $clauses = array_values(array_filter([
            match ($this->stockPosition()) {
                'nil' => 'the nil stock position'.$at,
                'current' => 'the current stock position'.$at,
                default => null,
            },
            $amc === null ? null : 'the average monthly consumption of approximately '.$amc,
            ($note = $this->text($this->req('outstanding_order_note'))) === null ? null : rtrim($note, '.'),
        ]));

        $noun = self::TYPE_NOUNS[$this->kind];
        $lead = $clauses === []
            ? "Approval is requested for the following {$noun}:"
            : 'In light of '.$this->joinList($clauses, serial: true).", approval is requested for the following {$noun}:";

        $rows = array_values(array_filter($this->kind === self::VARIATION ? $this->variationDetailRows() : $this->purchaseDetailRows(),
            fn (array $row) => $row[1] !== null));

        return [
            $this->paragraph($lead),
            $this->table(['Description', 'Details'], $rows, 'details'),
        ];
    }

    /** @return list<array{0: string, 1: string|null}> */
    private function purchaseDetailRows(): array
    {
        $recommended = $this->case->recommendedQuotation();
        $quantity = $this->purchaseQuantity();
        $unit = $this->unitLower();
        $total = $recommended?->totalPrice();

        return [
            ['Item', $this->item()],
            ['DIN', $this->text($this->req('din'))],
            ['Recommended Supplier', $this->text($recommended?->supplier_name)],
            ['Quantity', $quantity === null ? null : Display::quantity($quantity).($unit === null ? '' : " {$unit}")],
            ['Total Cost', $total === null ? null : Display::money($total)],
            ['Purpose', $this->text($this->req('purpose_summary'))],
        ];
    }

    /** @return list<array{0: string, 1: string|null}> */
    private function variationDetailRows(): array
    {
        $rows = [['Item', $this->item()], ['DIN', $this->text($this->req('din'))]];
        foreach ($this->variationRows() as $v) {
            $rows[] = ['Variation Type', $this->text($v->variation_type)];
            $rows[] = ['Existing Approved Detail', $this->text($v->existing_detail)];
            $rows[] = ['Proposed Revised Detail', $this->text($v->proposed_detail)];
            $rows[] = ['Effective Date', $this->text($v->effective_detail)];
        }

        return $rows;
    }

    /**
     * The Quotation Comparison annex: its header rows and one row per quotation
     * received, the recommended one marked. Null without a quotation.
     *
     * @return array{header: list<array{0: string, 1: string}>, rows: list<array{cells: list<string>, recommended: bool}>}|null
     */
    private function annex(): ?array
    {
        $received = $this->receivedQuotations();
        if ($received->isEmpty()) {
            return null;
        }

        $requested = $this->req('quantity_requested');
        $unit = $this->unitLower();
        $header = array_values(array_filter([
            ['RFQ No.', $this->text($this->req('rfq_number'))],
            ['Item', $this->item()],
            ['DIN', $this->text($this->req('din'))],
            ['Required Quantity', $requested === null ? null : Display::quantity((string) $requested).($unit === null ? '' : " {$unit}")],
        ], fn (array $row) => $row[1] !== null && $row[1] !== ''));

        $money = fn (?string $v) => $v === null ? '' : Display::money($v);

        return [
            'header' => $header,
            'rows' => $received->map(fn (CaseQuotation $q, int $i) => [
                'recommended' => (bool) $q->is_recommended,
                'cells' => [
                    (string) ($i + 1),
                    (string) $q->supplier_name,
                    $q->quantity_offered === null ? '' : Display::quantity((string) $q->quantity_offered),
                    $q->unit_price === null ? '' : Display::price((string) $q->unit_price),
                    $money($q->subtotal()),
                    $money($q->vatAmount()),
                    $money($q->totalPrice()),
                    $this->exStock($q) ?? '',
                    $q->lead_time_days === null ? '' : $q->lead_time_days.' '.($q->lead_time_days === 1 ? 'day' : 'days'),
                    $this->text($q->brand) ?? '',
                    $this->text($q->manufacturer) ?? '',
                    $this->text($q->country_of_origin) ?? '',
                    $this->text($q->remarks) ?? '',
                ],
            ])->values()->all(),
        ];
    }

    /** Ex Stock: the quantity available from stock (Kyle, 2026-11-24); an older row's Yes/No. */
    private function exStock(CaseQuotation $q): ?string
    {
        if ($q->quantity_available_now !== null) {
            return Display::quantity((string) $q->quantity_available_now);
        }

        return $q->ex_stock === null ? null : ($q->ex_stock ? 'Yes' : 'No');
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

    /* ---- what the sections read ---- */

    private function req(string $field): mixed
    {
        return $this->case->requisition?->{$field};
    }

    /** "nil" (no stock on hand, or 0), "current" (above 0), or null when the quantity on hand was not entered. */
    private function stockPosition(): ?string
    {
        $r = $this->case->requisition;

        return match (true) {
            $r === null => null,
            (bool) $r->no_stock_on_hand => 'nil',
            $r->quantity_on_hand === null => null,
            Decimal::compare((string) $r->quantity_on_hand, '0') <= 0 => 'nil',
            default => 'current',
        };
    }

    /** "10,417 tablets"; null without a consumption. */
    private function consumption(): ?string
    {
        $amc = $this->req('average_monthly_consumption');
        if ($amc === null) {
            return null;
        }
        $unit = $this->unitLower();

        return Display::quantity((string) $amc).($unit === null ? '' : " {$unit}");
    }

    /** The quantity being bought: the recommended quotation's, else the quantity requested. */
    private function purchaseQuantity(): ?string
    {
        $offered = $this->case->recommendedQuotation()?->quantity_offered;

        return $offered !== null ? (string) $offered : ($this->req('quantity_requested') === null ? null : (string) $this->req('quantity_requested'));
    }

    /** Kyle, 2026-11-27: the Approved Tender Excesses table as the form shows it (RequestSections). */
    private function showsPreviousOrders(): bool
    {
        return RequestSections::showsPreviousOrders((string) $this->case->procurementType?->code, $this->case->previous_request_in_cycle);
    }

    /** Variation rows, only where the form shows them (Tender Variation). */
    private function variationRows(): Collection
    {
        return RequestSections::isVisible((string) $this->case->procurementType?->code, 'variation_details', $this->case->previous_request_in_cycle)
            ? $this->case->variationRows
            : collect();
    }

    /** "Change of Manufacturer and Country of Origin" from the variation rows' types, wrapped; empty without any. */
    private function variationPhrase(string $before, string $after = ''): string
    {
        $types = $this->variationRows()
            ->map(fn (CaseVariationRow $r) => trim((string) $r->variation_type))
            ->filter()->unique(fn (string $t) => mb_strtolower($t))->values()->all();

        return $types === [] ? '' : $before.$this->joinList($types).$after;
    }

    /** @return Collection<int, CaseQuotation> */
    private function receivedQuotations(): Collection
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

    /** The item name alone ("Rivaroxaban"), for section 4. */
    private function shortItem(): string
    {
        return trim((string) $this->req('item_description'));
    }

    private function itemWithDin(string $dash): string
    {
        $din = trim((string) $this->req('din'));

        return $this->item().($din === '' ? '' : "{$dash}DIN {$din}");
    }

    /* ---- formatting ---- */

    /** "twenty-nine thousand nine hundred and eighty-eight (29,988) tablets"; null without a quantity. */
    private function quantityWords(?string $quantity): ?string
    {
        $words = NumberWords::withFigures($quantity);
        $unit = $this->unitLower();

        return $words === null ? null : $words.($unit === null ? '' : " {$unit}");
    }

    /** The unit of measure as running text uses it ("tablets", "each"); null when not entered. */
    private function unitLower(): ?string
    {
        $unit = $this->text($this->req('unit_of_measure'));

        return $unit === null ? null : mb_strtolower($unit);
    }

    /**
     * "6,250 boxes of 28 tablets" with the row's pack size, else "60,000 each"
     * with the unit of measure; "0" stays bare, as the paper letters show it.
     */
    private function withUnit(?string $quantity, ?string $packSize = null): ?string
    {
        if ($quantity === null || ! is_numeric($quantity)) {
            return null;
        }

        $figures = Display::quantity($quantity);
        $unit = $this->text($packSize) === null ? $this->unitLower() : mb_strtolower((string) $this->text($packSize));

        return $figures === '0' || $unit === null ? $figures : "{$figures} {$unit}";
    }

    /** "$368.20 per box of 28 tablets" with a pack size; "$95.00 each"; "$2.30 per tablet". */
    private function unitCost(?string $price, ?string $packSize = null): ?string
    {
        if ($price === null) {
            return null;
        }

        $figures = Display::price($price);
        $unit = $this->text($packSize) === null ? $this->unitLower() : mb_strtolower((string) $this->text($packSize));

        return match (true) {
            $unit === null => $figures,
            $unit === 'each' => "{$figures} each",
            default => "{$figures} per ".$this->singular($unit),
        };
    }

    /** The first word made singular: "boxes of 28 tablets" -> "box of 28 tablets", "tablets" -> "tablet". */
    private function singular(string $unit): string
    {
        [$first, $rest] = array_pad(explode(' ', $unit, 2), 2, null);

        return Str::singular($first).($rest === null ? '' : ' '.$rest);
    }

    /** "Bayer AG (Germany)". */
    private function manufacturer(?string $manufacturer, ?string $country): string
    {
        $manufacturer = $this->text($manufacturer);
        $country = $this->text($country);

        return match (true) {
            $manufacturer !== null && $country !== null => "{$manufacturer} ({$country})",
            default => $manufacturer ?? ($country === null ? '' : "({$country})"),
        };
    }

    /** "one (1) supplier", "two (2) suppliers". */
    private function counted(int $n, string $singular, string $plural): string
    {
        return NumberWords::spell($n).' ('.number_format($n).') '.($n === 1 ? $singular : $plural);
    }

    /**
     * @param  list<string>  $items  "a", "a and b", "a, b and c" ($serial: "a, b, and c", as the letter's
     *                               section 6 lead-in reads)
     */
    private function joinList(array $items, bool $serial = false): string
    {
        if (count($items) < 2) {
            return $items[0] ?? '';
        }

        return implode(', ', array_slice($items, 0, -1)).($serial && count($items) > 2 ? ', and ' : ' and ').end($items);
    }

    /** @return list<string> The text's paragraphs (split on blank lines), trimmed. */
    private function paragraphs(mixed $text): array
    {
        $text = $this->text($text);

        return $text === null ? [] : array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/u', $text) ?: []), fn (string $p) => $p !== ''));
    }

    private function paragraph(string $text): array
    {
        return ['type' => 'p', 'text' => $text];
    }

    /** @param  list<string>  $head  @param  list<list<string|null>>  $rows */
    private function table(array $head, array $rows, string $style = ''): array
    {
        return ['type' => 'table', 'head' => $head, 'rows' => $rows, 'style' => $style];
    }

    private function subsection(string $title, array $blocks): array
    {
        return ['type' => 'subsection', 'number' => '', 'title' => $title, 'blocks' => $blocks];
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
