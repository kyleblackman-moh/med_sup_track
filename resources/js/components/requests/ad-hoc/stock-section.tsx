import { EntryRow, EntryTable, type EntryColumn } from '@/components/ui/entry-table';
import { type FormExpiry } from '@/types/ad-hoc';
import { TriangleAlert } from 'lucide-react';
import { collectRowErrors, EntryInput } from './entry-fields';
import { CheckboxField, FieldGrid, Figure, GridRow, TableError, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { AddRowButton, RemoveRowButton, RowsNote } from './row-controls';

/** A typed quantity that is zero ("0", "0.00"); empty is not zero. */
const isZero = (value: string) => /^\s*0*(\.0*)?\s*$/.test(value) && /\d/.test(value);

/**
 * V2-2 section "Stock". Kyle, 2026-11-24:
 * - The average monthly consumption is a plain number (no unit after it).
 * - The quantity on hand is typed (requisition.quantity_on_hand), with the
 *   unit of measure after it. "No stock on hand" is a tick box that sets it to
 *   0 (unticking clears it); the server records no stock on hand whenever the
 *   quantity on hand is 0 (StockOnHand), so typing 0 ticks it too.
 * - A stock batch is its expiry date only.
 * Days on hand and the expiry warning are the server's; the warning never
 * blocks a submit.
 */
export function StockSection({ api }: { api: FormApi }) {
    const { state, config, form } = api;
    const unit = text(state.requisition.unit_of_measure).trim();
    const rows = state.rows.stock_expiries;
    const onHand = text(state.requisition.quantity_on_hand);
    const noStock = isZero(onHand);
    const optional = (name: string) => api.optional('stock', name);
    const cap = config.row_caps.stock_expiries;
    const tableError = api.tableError('stock_expiries');

    const field = (name: string) => ({
        id: fieldId.requisition(name),
        value: text(state.requisition[name]),
        onChange: (value: string) => api.setField(name, value),
        error: api.fieldError(name),
        optional: optional(name),
    });

    const columns: EntryColumn[] = [{ key: 'expiry_date', label: 'Expiry Date', width: 'sm' }];

    return (
        <FieldGrid>
            <TextField
                {...field('stock_location')}
                width="md"
                label="Location"
                maxLength={200}
                hint="Where the stock is held, for example Central stores."
            />
            <TextField
                {...field('average_monthly_consumption')}
                width="xs"
                label="Average Monthly Consumption"
                inputMode="decimal"
                hint="Enter 0 if the item has not been used."
            />
            <TextField {...field('quantity_on_hand')} width="xs" label="Quantity on Hand" inputMode="decimal" suffix={unit || undefined} />
            <CheckboxField
                width="xs"
                id={fieldId.requisition('no_stock_on_hand')}
                label="No Stock on Hand"
                checked={noStock}
                onChange={(checked) => api.setField('quantity_on_hand', checked ? '0' : '')}
                hint="Sets the quantity on hand to 0."
            />
            {
                <GridRow>
                    <div id={fieldId.table('stock_expiries')} tabIndex={-1} className="grid gap-2 outline-none">
                        <TableError message={tableError || undefined} />
                        <EntryTable
                            caption="Stock Batches"
                            breakpoint="32rem"
                            columns={columns}
                            empty={rows.length === 0 && <RowsNote>No batches yet.</RowsNote>}
                            addAction={
                                <AddRowButton
                                    label="Add Batch"
                                    disabled={rows.length >= cap}
                                    cap={cap}
                                    onClick={() => api.addRow('stock_expiries')}
                                />
                            }
                        >
                            {rows.map((row, index) => {
                                const rowLabel = `Batch ${index + 1}`;
                                // FORM-ERR-1 (A): every message of the row in one list under it, in column order.
                                const errorsId = fieldId.rowErrors('stock_expiries', row.key);
                                const errors = collectRowErrors(['id', ...columns.map((c) => c.key)], (name) =>
                                    api.cellError('stock_expiries', row, index, name),
                                );

                                return (
                                    <EntryRow
                                        key={row.key}
                                        label={rowLabel}
                                        errors={errors}
                                        errorsId={errorsId}
                                        remove={
                                            <RemoveRowButton
                                                label={`Remove batch ${index + 1}`}
                                                onClick={() => api.removeRow('stock_expiries', row.key)}
                                            />
                                        }
                                    >
                                        {columns.map((column) => (
                                            <EntryInput
                                                key={column.key}
                                                id={fieldId.cell('stock_expiries', row.key, column.key)}
                                                label={column.label}
                                                rowLabel={rowLabel}
                                                kind="date"
                                                width={column.width}
                                                value={text(row.values[column.key])}
                                                onChange={(value) => api.setCell('stock_expiries', row.key, column.key, value)}
                                                error={api.cellError('stock_expiries', row, index, column.key)}
                                                errorListId={errorsId}
                                                required={optional(`stock_expiries.${column.key}`) === false}
                                            />
                                        ))}
                                    </EntryRow>
                                );
                            })}
                        </EntryTable>
                    </div>
                </GridRow>
            }

            <GridRow>
                <FieldGrid as="dl">
                    <Figure width="xs" label="Days on Hand" value={form.expiry.days_on_hand} />
                </FieldGrid>
                {noStock && (
                    <p role="status" className="mt-3 flex items-center gap-2 text-sm">
                        <span className="badge badge-soft badge-warning badge-sm capitalize">No stock on hand</span>
                        <span className="text-base-content/70">The quantity on hand is 0.</span>
                    </p>
                )}
            </GridRow>

            <ExpiryBanner expiry={form.expiry} />
        </FieldGrid>
    );
}

/** The expiry warning (warning tone, not blocking), the whole grid row. Every word of it comes from the server's items. */
function ExpiryBanner({ expiry }: { expiry: FormExpiry }) {
    if (expiry.at_risk.length === 0 && expiry.expired.length === 0) {
        return null;
    }

    return (
        <GridRow>
            <div role="status" className="alert alert-warning alert-soft items-start text-sm">
                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <div className="space-y-1">
                    <p className="font-semibold">Some stock may expire before it is used</p>
                    <ul className="space-y-0.5">
                        {expiry.at_risk.map((item) => (
                            <li key={`risk-${item.batch}`}>
                                Batch expiring {item.expiry_date}: about {item.cover_days} days of cover, expires in {item.days_to_expiry} days
                            </li>
                        ))}
                        {expiry.expired.map((item) => (
                            <li key={`expired-${item.batch}`}>Batch expiring {item.expiry_date} has expired</li>
                        ))}
                    </ul>
                    <p>You can still submit this request.</p>
                </div>
            </div>
        </GridRow>
    );
}
