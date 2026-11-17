import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type AdHocConfig, type AdHocDetail, type ChildSection, type SectionIssue, type SectionKey, type SectionStatus } from '@/types/ad-hoc';
import { type DocumentGroup, type FieldValue, type Row } from '@/types/requests';
import { type ReactNode, type RefObject } from 'react';
import { CHILD_SECTIONS, fieldId, type FormState } from './form-state';

/** Radio groups are focused on their first option. */
const RADIO_FIELDS = ['tender_outcome', 'rfq_audience'];

/** The DOM id to focus for an issue: the field, the row's cell, or the table. */
export function targetFor(issue: SectionIssue, state: FormState): string {
    const [first, second] = issue.key.split('.');

    if (first === 'requisition') {
        return RADIO_FIELDS.includes(second) ? `${fieldId.requisition(second)}-0` : fieldId.requisition(second);
    }
    if (first === 'documents') {
        return fieldId.table('documents');
    }
    if ((CHILD_SECTIONS as string[]).includes(first)) {
        const section = first as ChildSection;
        const row = issue.row_id !== null ? state.rows[section].find((r) => r.id === issue.row_id) : undefined;

        return row ? fieldId.cell(section, row.key, issue.field.split('.')[1]) : fieldId.table(section);
    }

    return '';
}

interface IssuesPanelProps {
    panelRef: RefObject<HTMLElement | null>;
    sections: SectionStatus[];
    state: FormState;
    onJump: (key: SectionKey, focusId: string) => void;
}

/** What still needs doing, grouped by section, with jump links. Receives focus when shown. */
export function IssuesPanel({ panelRef, sections, state, onJump }: IssuesPanelProps) {
    const withIssues = sections.filter((section) => section.issues.length > 0);
    const count = withIssues.reduce((n, section) => n + section.issues.length, 0);

    return (
        <section
            ref={panelRef}
            tabIndex={-1}
            aria-labelledby="review-issues-heading"
            className="border-destructive bg-card focus-visible:ring-ring rounded-lg border p-6 focus-visible:ring-2 focus-visible:outline-hidden"
        >
            <h2 id="review-issues-heading" className="text-base font-semibold">
                Before you can submit, {count === 1 ? '1 thing needs' : `${count} things need`} attention
            </h2>
            <div className="mt-4 space-y-4">
                {withIssues.map((section) => (
                    <div key={section.key}>
                        <h3 className="text-sm font-semibold">{section.label}</h3>
                        <ul className="mt-1 list-disc space-y-1 pl-5 text-sm">
                            {section.issues.map((issue) => {
                                const target = targetFor(issue, state);

                                return (
                                    <li key={issue.key}>
                                        <a
                                            href={`#${target}`}
                                            className="text-primary underline underline-offset-4"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                onJump(section.key, target);
                                            }}
                                        >
                                            {issue.message}
                                        </a>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </div>
        </section>
    );
}

const show = (value: FieldValue | undefined): ReactNode => (value === null || value === undefined || value === '' ? '—' : String(value));

function Item({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5 sm:grid-cols-[14rem_minmax(0,1fr)] sm:gap-4">
            <dt className="text-muted-foreground text-sm">{label}</dt>
            <dd className="text-sm tabular-nums">{children}</dd>
        </div>
    );
}

function Rows({ caption, rows, columns }: { caption: string; rows: Row[]; columns: [string, string, ((row: Row) => ReactNode)?][] }) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <caption className="sr-only">{caption}</caption>
                <thead className="bg-muted">
                    <tr>
                        {columns.map(([, label]) => (
                            <th key={label} scope="col" className="px-3 py-2 text-left text-xs font-medium">
                                {label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={String(row.id)} className="border-t">
                            {columns.map(([name, label, render]) => (
                                <td key={label} className="px-3 py-2 tabular-nums">
                                    {render ? render(row) : show(row[name])}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

interface AdHocSummaryProps {
    detail: AdHocDetail;
    config: Pick<AdHocConfig, 'options' | 'not_awarded'>;
    documents: DocumentGroup[];
}

/**
 * Everything the vendor entered, section by section in form order, from the
 * server's saved copy: the Review and submit panel and the request page after
 * submitting show this same summary. Document names and scan states included.
 */
export function AdHocSummary({ detail, config, documents }: AdHocSummaryProps) {
    const r = detail.requisition;
    const unit = r.unit_of_measure ? ` ${r.unit_of_measure}` : '';
    const label = (options: { value: string; label: string }[], value: FieldValue) => options.find((o) => o.value === value)?.label ?? show(value);
    const awardSupplier = (id: FieldValue) => detail.tender_awards.find((a) => a.id === id)?.supplier_name ?? '—';
    const awarded = r.tender_outcome !== null && r.tender_outcome !== config.not_awarded;
    const f = detail.figures;

    return (
        <div className="space-y-6">
            <div className="space-y-2">
                <h3 className="text-sm font-semibold">Item</h3>
                <dl className="space-y-1">
                    <Item label="Item description">{show(r.item_description)}</Item>
                    <Item label="Strength">{show(r.strength)}</Item>
                    <Item label="Dosage form">{show(r.dosage_form)}</Item>
                    <Item label="DIN">{show(r.din)}</Item>
                    <Item label="Quantity requested">
                        {show(r.quantity_requested)}
                        {unit}
                    </Item>
                    <Item label="Unit of measure">{show(r.unit_of_measure)}</Item>
                    <Item label="Clinical importance">{show(r.clinical_note)}</Item>
                </dl>
            </div>

            <div className="space-y-2">
                <h3 className="text-sm font-semibold">Tender background</h3>
                <dl className="space-y-1">
                    <Item label="Tender period / reference">{show(r.tender_reference)}</Item>
                    <Item label="Outcome">{label(config.options.tender_outcome, r.tender_outcome)}</Item>
                    {awarded && <Item label="Number of suppliers awarded">{f?.suppliers_awarded ?? 0}</Item>}
                </dl>
                {awarded && (
                    <>
                        <Rows
                            caption="Awards"
                            rows={detail.tender_awards}
                            columns={[
                                ['supplier_name', 'Supplier'],
                                ['manufacturer', 'Manufacturer'],
                                ['brand', 'Brand'],
                                ['quantity_awarded', 'Quantity awarded'],
                                ['percentage', '% awarded', (row) => (row.derived?.percentage ? `${row.derived.percentage}%` : '—')],
                                ['unit_cost', 'Unit cost'],
                                ['total', 'Total cost', (row) => row.derived?.total ?? '—'],
                            ]}
                        />
                        <Rows
                            caption="Orders"
                            rows={detail.purchase_orders}
                            columns={[
                                ['award_id', 'Supplier', (row) => awardSupplier(row.award_id)],
                                ['po_number', 'PO #'],
                                ['quantity_ordered', 'Quantity ordered'],
                                ['ordered_on', 'Date ordered'],
                                ['quantity_delivered', 'Quantity delivered'],
                                ['outstanding', 'Quantity outstanding', (row) => row.derived?.quantity_outstanding ?? '—'],
                                [
                                    'delivery',
                                    'Delivery date / status',
                                    (row) => [row.delivery_eta, row.delivery_status].filter(Boolean).join(', ') || '—',
                                ],
                            ]}
                        />
                        <dl>
                            <Item label="Issues affecting the tender quantity">{show(r.tender_issue_explanation)}</Item>
                        </dl>
                    </>
                )}
            </div>

            <div className="space-y-2">
                <h3 className="text-sm font-semibold">Stock</h3>
                <dl className="space-y-1">
                    <Item label="Location">{show(r.stock_location)}</Item>
                    <Item label="Average monthly consumption">
                        {show(r.average_monthly_consumption)}
                        {unit}
                    </Item>
                    <Item label="Stock on hand">
                        {r.no_stock_on_hand ? 'No stock on hand' : show(r.derived.stock_on_hand)}
                        {!r.no_stock_on_hand && r.derived.stock_on_hand !== null && unit}
                    </Item>
                </dl>
                {!r.no_stock_on_hand && (
                    <Rows
                        caption="Stock batches"
                        rows={detail.stock_expiries}
                        columns={[
                            ['quantity', 'Quantity'],
                            ['expiry_date', 'Expiry date'],
                        ]}
                    />
                )}
            </div>

            <div className="space-y-2">
                <h3 className="text-sm font-semibold">Quotations</h3>
                <dl className="space-y-1">
                    <Item label="RFQ sent to">{label(config.options.rfq_audience, r.rfq_audience)}</Item>
                </dl>
                <Rows
                    caption="Quotations"
                    rows={detail.quotations}
                    columns={[
                        ['supplier_name', 'Supplier'],
                        ['manufacturer', 'Manufacturer'],
                        ['brand', 'Brand'],
                        ['unit_price', 'Unit price'],
                        ['quantity_offered', 'Quantity offered'],
                        ['lead_time_days', 'Lead time (days)'],
                        ['considered', 'Considered', (row) => (row.considered ? 'Yes' : `No: ${show(row.reason_accepted_excluded)}`)],
                        ['is_recommended', 'Recommended', (row) => (row.is_recommended ? 'Recommended' : '')],
                    ]}
                />
                <dl className="space-y-1">
                    <Item label="Quantity to purchase">
                        {show(r.quantity_to_purchase)}
                        {unit}
                    </Item>
                    <Item label="Quotations received">{f?.quotations_received ?? 0}</Item>
                    <Item label="Quotations considered">{f?.quotations_considered ?? 0}</Item>
                    <Item label="Recommended unit price">{show(f?.recommended_unit_price)}</Item>
                    <Item label="Total cost (recommended)">{show(f?.total_cost)}</Item>
                </dl>
            </div>

            {(r.tender_price !== null || r.previous_price !== null || r.quoted_price_acceptable !== null || r.price_note) && (
                <div className="space-y-2">
                    <h3 className="text-sm font-semibold">Price comparison</h3>
                    <dl className="space-y-1">
                        <Item label="Tender price">{show(r.tender_price)}</Item>
                        <Item label="Previous price">{show(r.previous_price)}</Item>
                        <Item label="Quoted price">{show(f?.recommended_unit_price)}</Item>
                        <Item label="Change against tender price">
                            {f?.price_change_vs_tender_percent ? `${f.price_change_vs_tender_percent}%` : '—'}
                        </Item>
                        <Item label="Change against previous price">
                            {f?.price_change_vs_previous_percent ? `${f.price_change_vs_previous_percent}%` : '—'}
                        </Item>
                        <Item label="Quoted price is acceptable">
                            {r.quoted_price_acceptable === null ? '—' : r.quoted_price_acceptable ? 'Yes' : 'No'}
                        </Item>
                        <Item label="Note">{show(r.price_note)}</Item>
                    </dl>
                </div>
            )}

            <div className="space-y-2">
                <h3 className="text-sm font-semibold">Documents</h3>
                <dl className="space-y-1">
                    {documents.map((group) => (
                        <Item key={group.purpose} label={group.label}>
                            {group.documents.length === 0
                                ? 'None'
                                : group.documents.map((doc) => `${doc.display_name} (${doc.status_label.toLowerCase()})`).join(', ')}
                        </Item>
                    ))}
                </dl>
            </div>
        </div>
    );
}

interface SummaryPanelProps {
    panelRef: RefObject<HTMLElement | null>;
    detail: AdHocDetail;
    config: AdHocConfig;
    documents: DocumentGroup[];
    onSubmit: () => void;
}

/** A read-only summary of everything saved (the server's copy), then Submit. */
export function SummaryPanel({ panelRef, detail, config, documents, onSubmit }: SummaryPanelProps) {
    return (
        <section
            ref={panelRef}
            tabIndex={-1}
            aria-labelledby="review-summary-heading"
            className="bg-card focus-visible:ring-ring space-y-6 rounded-lg border p-6 focus-visible:ring-2 focus-visible:outline-hidden"
        >
            <div>
                <h2 id="review-summary-heading" className="text-base font-semibold">
                    Review your request
                </h2>
                <p className="text-muted-foreground text-sm">Everything below is saved. Check it, then submit.</p>
            </div>

            <AdHocSummary detail={detail} config={config} documents={documents} />

            <div>
                <Button type="button" className="h-11 md:h-9" onClick={onSubmit}>
                    Submit request
                </Button>
            </div>
        </section>
    );
}

interface SubmitDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    hardcopyNotice: string;
    submitting: boolean;
    onConfirm: () => void;
}

/** Confirm before submitting. Reuses the server's hardcopy wording (no deadline). */
export function SubmitDialog({ open, onOpenChange, hardcopyNotice, submitting, onConfirm }: SubmitDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Submit this request?</DialogTitle>
                    <DialogDescription>Once submitted, the request can no longer be changed. You will get a reference number.</DialogDescription>
                </DialogHeader>
                <p className="rounded-md border p-3 text-sm font-medium">{hardcopyNotice}</p>
                <DialogFooter>
                    <Button type="button" variant="outline" className="h-11 md:h-9" onClick={() => onOpenChange(false)} disabled={submitting}>
                        Cancel
                    </Button>
                    <Button type="button" className="h-11 md:h-9" onClick={onConfirm} disabled={submitting}>
                        {submitting ? 'Submitting...' : 'Submit request'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
