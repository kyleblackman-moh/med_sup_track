import { EntrySheet } from '@/components/ui/entry-sheet';
import { RowErrorList } from '@/components/ui/entry-table';
import { quotationPrice } from '@/lib/quotation-price';
import { type ReactNode } from 'react';
import { collectRowErrors, EntryInlineCheck } from './entry-fields';
import { FieldGrid, FieldHint, GridRow, inputClass, RadioGroupField, TableError, TextAreaField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { PriceSection } from './price-section';
import { AddRowButton, RemoveRowButton, RowsNote } from './row-controls';

/**
 * Backlog v0.4 (2026-11-25): each quotation is one supplier block, a small
 * table, in the comparison order: the Supplier in the header (with the narrow
 * Considered and Recommended controls and remove), then two table lines, each
 * a row of column headers over a row of fields (spans of the 15 tracks):
 *   Quantity | Unit Price | Subtotal | VAT | Total Price
 *   Ex Stock | Lead Time (Days) | Brand | Manufacturer | Country of Origin | Remarks
 * Subtotal, VAT and Total Price are read-only, worked out live
 * (lib/quotation-price.ts). The layout is EntrySheet
 * (components/ui/entry-sheet.tsx); the test reads SHEET_LINES.
 */
const SHEET_LINES: { key: string; label: string; span: number; computed?: true }[][] = [
    [
        { key: 'quantity_offered', label: 'Quantity', span: 3 },
        { key: 'unit_price', label: 'Unit Price', span: 3 },
        { key: 'subtotal', label: 'Subtotal', span: 3, computed: true },
        { key: 'vat', label: 'VAT', span: 3, computed: true },
        { key: 'total_price', label: 'Total Price', span: 3, computed: true },
    ],
    [
        { key: 'quantity_available_now', label: 'Ex Stock', span: 2 },
        { key: 'lead_time_days', label: 'Lead Time (Days)', span: 2 },
        { key: 'brand', label: 'Brand', span: 3 },
        { key: 'manufacturer', label: 'Manufacturer', span: 3 },
        { key: 'country_of_origin', label: 'Country of Origin', span: 2 },
        { key: 'remarks', label: 'Remarks', span: 3 },
    ],
];

/** A read-only figure in a sheet cell, level with the inputs beside it. */
function SheetFigure({ value, strong }: { value: string; strong?: boolean }) {
    return (
        <output className={strong ? 'flex min-h-8 items-center px-1 font-semibold tabular-nums' : 'flex min-h-8 items-center px-1 tabular-nums'}>
            {value}
        </output>
    );
}

/** Every message of an entry in one list under it, in the order the block shows the fields. */
const ERROR_ORDER = [
    'id',
    'supplier_name',
    'considered',
    'is_recommended',
    'quantity_offered',
    'unit_price',
    'quantity_available_now',
    'lead_time_days',
    'brand',
    'manufacturer',
    'country_of_origin',
    'remarks',
    'reason_accepted_excluded',
];

/** "1234.50" to "$1,234.50", as the server's Display::money. */
function money(amount: string): string {
    const [whole, fraction] = amount.split('.');

    return `$${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction}`;
}

/**
 * V2-2 section "Quotation comparison": who received the RFQ, one supplier
 * block per quotation (a reason under its table only when the quotation was
 * not considered), the quantity to purchase and the server's total cost.
 * The optional price comparison (fields of this section on the server)
 * follows. Presentation only: the server still requires the reason on a
 * row that is not considered, and one recommended quotation.
 */
export function QuotationSection({ api }: { api: FormApi }) {
    const { state, config, form } = api;
    const rows = state.rows.quotations;
    const optional = (name: string) => api.optional('quotation_comparison', name);
    const cap = config.row_caps.quotations;
    const tableError = [api.tableError('quotations'), api.tableError('quotations.recommended')].filter(Boolean).join(' ');

    return (
        <FieldGrid>
            <RadioGroupField
                width="lg"
                inline
                id={fieldId.requisition('rfq_audience')}
                legend={'RFQ Sent To'}
                options={config.options.rfq_audience}
                value={text(state.requisition.rfq_audience)}
                onChange={(value) => api.setField('rfq_audience', value)}
                error={api.fieldError('rfq_audience')}
            />

            <GridRow>
                <div id={fieldId.table('quotations')} tabIndex={-1} className="grid gap-2 outline-none">
                    <FieldHint>
                        One block per supplier. Mark each quotation Considered or Recommended (one recommended in all); give a reason for any that is
                        neither.
                    </FieldHint>
                    <TableError message={tableError || undefined} />
                    <div role="group" aria-label="Quotations Received" className="grid gap-3">
                        {rows.length === 0 && (
                            <div className="border-base-300 rounded-box border border-dashed">
                                <RowsNote>No quotations yet.</RowsNote>
                            </div>
                        )}
                        {rows.map((row, index) => {
                            const rowLabel = `Quotation Row ${index + 1}`;
                            const id = (name: string) => fieldId.cell('quotations', row.key, name);
                            // FORM-ERR-1 (A): every message of the entry in one list, at the bottom of its block.
                            const errorsId = fieldId.rowErrors('quotations', row.key);
                            const errors = collectRowErrors(ERROR_ORDER, (name) => api.cellError('quotations', row, index, name));
                            const labelOf = (name: string) => SHEET_LINES.flat().find((c) => c.key === name)?.label ?? 'Supplier';
                            const input = (name: string, kind: 'text' | 'decimal' | 'integer' = 'text', maxLength?: number) => {
                                const error = api.cellError('quotations', row, index, name);

                                return (
                                    <input
                                        id={id(name)}
                                        type="text"
                                        // FORM-ERR-1 (E): a keyboard hint only; the server validates and owns the message.
                                        inputMode={kind === 'decimal' ? 'decimal' : kind === 'integer' ? 'numeric' : undefined}
                                        maxLength={maxLength}
                                        value={text(row.values[name])}
                                        className={inputClass(Boolean(error))}
                                        aria-label={`${labelOf(name)}, ${rowLabel}`}
                                        aria-required={optional(`quotations.${name}`) === false ? true : undefined}
                                        aria-invalid={error ? true : undefined}
                                        aria-describedby={error ? errorsId : undefined}
                                        onChange={(e) => api.setCell('quotations', row.key, name, e.target.value)}
                                    />
                                );
                            };
                            const considered = row.values.considered === true;
                            const recommended = row.values.is_recommended === true;
                            // Kyle, 2026-11-24: Considered OR Recommended, never both ticked. Recommended is stored as
                            // considered too (the server counts it as considered and asks no reason for it).
                            const status = recommended ? 'Recommended' : considered ? 'Considered' : 'Not Considered';
                            const supplier = text(row.values.supplier_name).trim();
                            // Live as the vendor types: the server's rule and rate (quotation-price.ts, form.display.vat_rate).
                            const price = quotationPrice(text(row.values.quantity_offered), text(row.values.unit_price), form.display.vat_rate);
                            const cells: Record<string, ReactNode> = {
                                quantity_offered: input('quantity_offered', 'decimal'),
                                unit_price: input('unit_price', 'decimal'),
                                quantity_available_now: input('quantity_available_now', 'decimal'),
                                lead_time_days: input('lead_time_days', 'integer'),
                                brand: input('brand', 'text', 200),
                                manufacturer: input('manufacturer', 'text', 200),
                                country_of_origin: input('country_of_origin', 'text', 100),
                                remarks: input('remarks', 'text', 255),
                                subtotal: <SheetFigure value={price ? money(price.subtotal) : '—'} />,
                                vat: <SheetFigure value={price ? money(price.vat) : '—'} />,
                                total_price: <SheetFigure value={price ? money(price.total) : '—'} strong />,
                            };

                            return (
                                <EntrySheet
                                    key={row.key}
                                    label={rowLabel}
                                    summary={
                                        <span className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                                            <span className="badge badge-ghost badge-sm shrink-0 capitalize tabular-nums">Quotation {index + 1}</span>
                                            <span
                                                className={
                                                    supplier ? 'min-w-0 flex-1 truncate font-medium' : 'text-base-content/70 min-w-0 flex-1 truncate'
                                                }
                                            >
                                                {supplier || 'No supplier yet'}
                                            </span>
                                            <span className="text-base-content/70 flex flex-wrap items-center gap-2 text-sm">
                                                {errors.length > 0 && (
                                                    <span className="badge badge-soft badge-error badge-sm capitalize">{errors.length} to fix</span>
                                                )}
                                                <span
                                                    className={
                                                        recommended
                                                            ? 'badge badge-soft badge-primary badge-sm capitalize'
                                                            : 'badge badge-ghost badge-sm capitalize'
                                                    }
                                                >
                                                    {status}
                                                </span>
                                                {price && <span className="tabular-nums">Total {money(price.total)}</span>}
                                            </span>
                                        </span>
                                    }
                                    title={
                                        <>
                                            <label htmlFor={id('supplier_name')} className="shrink-0 text-sm font-semibold capitalize">
                                                Supplier
                                            </label>
                                            {input('supplier_name', 'text', 200)}
                                        </>
                                    }
                                    controls={
                                        <div role="group" aria-label={`Choices, ${rowLabel}`} className="flex flex-wrap items-center gap-x-4">
                                            <EntryInlineCheck
                                                id={id('considered')}
                                                label="Considered"
                                                rowLabel={rowLabel}
                                                type="checkbox"
                                                checked={considered && !recommended}
                                                onChange={(checked) => {
                                                    // Ticking Considered on the recommended quotation takes the recommendation off it.
                                                    if (checked && recommended) {
                                                        api.setCell('quotations', row.key, 'is_recommended', false);
                                                    }
                                                    api.setCell('quotations', row.key, 'considered', checked);
                                                }}
                                            />
                                            <EntryInlineCheck
                                                id={id('is_recommended')}
                                                label="Recommended"
                                                rowLabel={rowLabel}
                                                type="radio"
                                                name="recommended-quotation"
                                                checked={recommended}
                                                onChange={() => {
                                                    api.chooseRecommended(row.key);
                                                    api.setCell('quotations', row.key, 'considered', true);
                                                }}
                                            />
                                        </div>
                                    }
                                    remove={
                                        <RemoveRowButton
                                            label={`Remove Quotation Row ${index + 1}`}
                                            onClick={() => api.removeRow('quotations', row.key)}
                                        />
                                    }
                                    lines={SHEET_LINES.map((line) =>
                                        line.map(({ computed, ...column }) => ({
                                            ...column,
                                            // A worked-out figure has no control, so its header is not a label.
                                            htmlFor: computed ? undefined : id(column.key),
                                            content: cells[column.key],
                                        })),
                                    )}
                                    below={
                                        (!considered || errors.length > 0) && (
                                            <>
                                                {
                                                    // Only for a quotation that was not considered; no space otherwise.
                                                    !considered && (
                                                        <TextAreaField
                                                            width="full"
                                                            id={id('reason_accepted_excluded')}
                                                            label="Reason Quotation Wasn't Considered"
                                                            srLabelSuffix={`, ${rowLabel}`}
                                                            rows={2}
                                                            maxLength={config.max_lengths.reason_accepted_excluded}
                                                            value={text(row.values.reason_accepted_excluded)}
                                                            onChange={(value) =>
                                                                api.setCell('quotations', row.key, 'reason_accepted_excluded', value)
                                                            }
                                                            error={api.cellError('quotations', row, index, 'reason_accepted_excluded')}
                                                            errorListId={errorsId}
                                                            optional={optional('quotations.reason_accepted_excluded')}
                                                            hint={`Up to ${config.max_lengths.reason_accepted_excluded} characters.`}
                                                        />
                                                    )
                                                }
                                                {/* FORM-ERR-1 (A): the entry's one error list. */}
                                                <RowErrorList id={errorsId} errors={errors} />
                                            </>
                                        )
                                    }
                                />
                            );
                        })}
                        <div>
                            <AddRowButton label="Add Quotation" disabled={rows.length >= cap} cap={cap} onClick={() => api.addRow('quotations')} />
                        </div>
                    </div>
                </div>
            </GridRow>

            <GridRow>
                <PriceSection api={api} />
            </GridRow>
        </FieldGrid>
    );
}
