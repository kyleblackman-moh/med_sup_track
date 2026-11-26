import { EntrySheet } from '@/components/ui/entry-sheet';
import { RowErrorList } from '@/components/ui/entry-table';
import { QuotationTotals } from '@/components/ui/quotation-totals';
import { money, quotationPrice } from '@/lib/quotation-price';
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
 * a row of column headers over a row of fields:
 *   Quantity | Unit Price | Ex Stock | Lead Time (Days) | Brand | Manufacturer | Country of Origin
 *   Remarks (the whole line)
 * Kyle, 2026-11-25: Subtotal, VAT and Total Price Incl. VAT sit under the
 * table as the block's totals (QuotationTotals), read-only and worked out live
 * (lib/quotation-price.ts). The layout is EntrySheet
 * (components/ui/entry-sheet.tsx); the test reads SHEET_LINES.
 *
 * Kyle, 2026-11-26: no Considered tick (every quotation is considered; the
 * save sends it so). The recommended quotation asks for its Reason for
 * Justification under its totals, required by the server.
 */
const SHEET_LINES: { key: string; label: string; span: number }[][] = [
    [
        { key: 'quantity_offered', label: 'Qty', span: 1 },
        { key: 'unit_price', label: 'Unit Price', span: 1 },
        { key: 'quantity_available_now', label: 'Ex Stock', span: 1 },
        { key: 'lead_time_days', label: 'Lead Time (Days)', span: 1 },
        { key: 'brand', label: 'Brand', span: 1 },
        { key: 'manufacturer', label: 'Manufacturer', span: 1 },
        { key: 'country_of_origin', label: 'Country of Origin', span: 1 },
    ],
    [{ key: 'remarks', label: 'Remarks', span: 7 }],
];

/**
 * Kyle, 2026-11-25: the four number columns are narrow and fixed (Lead Time the narrowest);
 * Brand, Manufacturer and Country of Origin share the rest. Remarks has the whole second line.
 */
const SHEET_COLUMNS = ['7rem', '8rem', '7rem', '6rem', undefined, undefined, undefined];

/** Every message of an entry in one list under it, in the order the block shows the fields. */
const ERROR_ORDER = [
    'id',
    'supplier_name',
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

/**
 * V2-2 section "Quotation comparison": who received the RFQ, one supplier
 * block per quotation (the Reason for Justification under the recommended
 * one's table), and the server's total cost. The optional price comparison
 * (fields of this section on the server) follows. Presentation only: the
 * server requires one recommended quotation and its justification.
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
                    <FieldHint>One block per supplier. Choose the recommended quotation and give the reason for recommending it.</FieldHint>
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
                            const recommended = row.values.is_recommended === true;
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
                                                {recommended && (
                                                    <span className="badge badge-soft badge-primary badge-sm capitalize">Recommended</span>
                                                )}
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
                                                id={id('is_recommended')}
                                                label="Recommended"
                                                rowLabel={rowLabel}
                                                type="radio"
                                                name="recommended-quotation"
                                                checked={recommended}
                                                onChange={() => api.chooseRecommended(row.key)}
                                            />
                                        </div>
                                    }
                                    remove={
                                        <RemoveRowButton
                                            label={`Remove Quotation Row ${index + 1}`}
                                            onClick={() => api.removeRow('quotations', row.key)}
                                        />
                                    }
                                    columns={SHEET_COLUMNS}
                                    lines={SHEET_LINES.map((line) =>
                                        line.map((column) => ({
                                            ...column,
                                            htmlFor: id(column.key),
                                            content: cells[column.key],
                                        })),
                                    )}
                                    below={
                                        <>
                                            <QuotationTotals label={rowLabel} price={price} />
                                            {
                                                // Kyle, 2026-11-26: only for the recommended quotation; no space otherwise.
                                                recommended && (
                                                    <TextAreaField
                                                        width="full"
                                                        id={id('reason_accepted_excluded')}
                                                        label="Reason for Justification"
                                                        srLabelSuffix={`, ${rowLabel}`}
                                                        rows={2}
                                                        maxLength={config.max_lengths.reason_accepted_excluded}
                                                        value={text(row.values.reason_accepted_excluded)}
                                                        onChange={(value) => api.setCell('quotations', row.key, 'reason_accepted_excluded', value)}
                                                        error={api.cellError('quotations', row, index, 'reason_accepted_excluded')}
                                                        errorListId={errorsId}
                                                        optional={optional('quotations.reason_accepted_excluded')}
                                                        hint={`Why this quotation is recommended. Up to ${config.max_lengths.reason_accepted_excluded} characters.`}
                                                    />
                                                )
                                            }
                                            {/* FORM-ERR-1 (A): the entry's one error list. */}
                                            <RowErrorList id={errorsId} errors={errors} />
                                        </>
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
