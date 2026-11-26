import { DatePicker, todayInPortOfSpain } from '@/components/ui/date-picker';
import { EntryCell, EntryRow, EntryTable, type EntryColumn, type EntryWidth } from '@/components/ui/entry-table';
import { ENTRY_INPUT_PAD } from '@/components/ui/field-width';
import { cn } from '@/lib/utils';
import { X } from 'lucide-react';
import { collectRowErrors, EntryInput, EntryValue } from './entry-fields';
import { FieldGrid, FieldHint, GridRow, inputClass, selectClass, TableError, TextAreaField } from './fields';
import { rowDisplay, text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { AddRowButton, RemoveRowButton, RowsNote } from './row-controls';

/**
 * V2-2 section "Existing approved arrangement": the orders placed against the
 * awarded suppliers (each picks its supplier from the SAVED award rows, which
 * have an id), the server's quantity outstanding per order, and one text box
 * for issues affecting the tender quantity.
 *
 * Kyle, 2026-11-25: the quantities and dates are narrow fixed columns (num,
 * date), so the text columns get the room. The
 * Delivery Status is Partial Delivery, Delivered, Not Delivered or Other.
 *
 * Kyle, 2026-11-26: the whole order on one line from a 65rem card (P.O # and
 * the quantities narrow, 8px gaps), and Other turns the status list itself into a text
 * box for what it means (required by the server); its X goes back to the list.
 */
export function ExistingArrangementSection({ api }: { api: FormApi }) {
    const rows = api.state.rows.purchase_orders;
    const awards = api.state.rows.tender_awards;
    const choosable = awards.filter((row) => row.id !== null && text(row.values.supplier_name).trim() !== '');
    const awaitingSave = awards.some((row) => row.id === null && text(row.values.supplier_name).trim() !== '');
    const optional = (name: string) => api.optional('existing_arrangement', name);
    const cap = api.config.row_caps.purchase_orders;
    const tableError = api.tableError('purchase_orders');
    const today = todayInPortOfSpain();

    const columns: EntryColumn[] = [
        { key: 'award_id', label: 'Supplier' },
        { key: 'po_number', label: 'P.O #', width: 'num' },
        { key: 'quantity_ordered', label: 'Qty Ordered', width: 'num' },
        { key: 'ordered_on', label: 'Date Ordered', width: 'date' },
        { key: 'quantity_delivered', label: 'Qty Delivered', width: 'num' },
        { key: 'delivery_state', label: 'Delivery Status' },
        { key: 'delivery_date', label: 'Delivery Date', width: 'date' },
        { key: 'delivery_note', label: 'Delivery Note No.', width: 'xs' },
        { key: 'quantity_outstanding', label: 'Qty Outstanding', width: 'value' },
    ];
    const label = (key: string) => columns.find((c) => c.key === key)?.label ?? key;
    const widthOf = (key: string): EntryWidth => columns.find((c) => c.key === key)?.width ?? 'md';

    return (
        <FieldGrid>
            <GridRow>
                <div id={fieldId.table('purchase_orders')} tabIndex={-1} className="grid gap-2 outline-none">
                    <FieldHint>One supplier can have several orders.</FieldHint>
                    <TableError message={tableError || undefined} />
                    <EntryTable
                        caption="Orders"
                        columns={columns}
                        breakpoint="65rem"
                        narrowRowLabel
                        empty={rows.length === 0 && <RowsNote>No orders yet.</RowsNote>}
                        addAction={
                            <AddRowButton label="Add Order" disabled={rows.length >= cap} cap={cap} onClick={() => api.addRow('purchase_orders')} />
                        }
                    >
                        {rows.map((row, index) => {
                            const rowLabel = `Order Row ${index + 1}`;
                            // FORM-ERR-1 (A): every message of the row in one list under it, in column order.
                            const errorsId = fieldId.rowErrors('purchase_orders', row.key);
                            const errorOf = (name: string) => api.cellError('purchase_orders', row, index, name);
                            const errors = collectRowErrors(
                                [
                                    'id',
                                    'award_id',
                                    'po_number',
                                    'quantity_ordered',
                                    'ordered_on',
                                    'quantity_delivered',
                                    'delivery_state',
                                    'delivery_status',
                                    'delivery_date',
                                    'delivery_note',
                                ],
                                errorOf,
                            );
                            const stateError = errorOf('delivery_state');
                            const otherError = errorOf('delivery_status');
                            const dateError = errorOf('delivery_date');
                            const deliveryState = text(row.values.delivery_state);
                            const input = (name: string, kind: 'text' | 'decimal' | 'date' = 'text', maxLength?: number) => (
                                <EntryInput
                                    id={fieldId.cell('purchase_orders', row.key, name)}
                                    label={label(name)}
                                    rowLabel={rowLabel}
                                    kind={kind}
                                    width={widthOf(name)}
                                    maxLength={maxLength}
                                    // Dates ordered are never in the future (Port of Spain today).
                                    max={kind === 'date' ? today : undefined}
                                    value={text(row.values[name])}
                                    onChange={(value) => api.setCell('purchase_orders', row.key, name, value)}
                                    error={api.cellError('purchase_orders', row, index, name)}
                                    errorListId={errorsId}
                                    required={optional(`purchase_orders.${name}`) === false}
                                />
                            );

                            const supplierId = fieldId.cell('purchase_orders', row.key, 'award_id');
                            const supplierError = api.cellError('purchase_orders', row, index, 'award_id');
                            const current = text(row.values.award_id);
                            const valid = choosable.some((award) => String(award.id) === current);
                            const deliveryId = fieldId.cell('purchase_orders', row.key, 'delivery_state');
                            const otherId = fieldId.cell('purchase_orders', row.key, 'delivery_status');
                            const isOther = deliveryState === 'OTHER';
                            // Its own cell id, so a jump to a delivery date error lands on the picker.
                            const deliveryDateId = fieldId.cell('purchase_orders', row.key, 'delivery_date');

                            return (
                                <EntryRow
                                    key={row.key}
                                    label={rowLabel}
                                    // Kyle, 2026-11-26: a coloured left edge per order, to tell one from the next.
                                    accent
                                    short={String(index + 1)}
                                    errors={errors}
                                    errorsId={errorsId}
                                    remove={
                                        <RemoveRowButton
                                            label={`Remove order row ${index + 1}`}
                                            onClick={() => api.removeRow('purchase_orders', row.key)}
                                        />
                                    }
                                >
                                    <EntryCell label={label('award_id')} htmlFor={supplierId} width={widthOf('award_id')}>
                                        <select
                                            id={supplierId}
                                            className={selectClass(Boolean(supplierError))}
                                            value={valid ? current : ''}
                                            disabled={choosable.length === 0}
                                            aria-label={`${label('award_id')}, ${rowLabel}`}
                                            aria-required={optional('purchase_orders.award_id') === false ? true : undefined}
                                            aria-invalid={supplierError ? true : undefined}
                                            aria-describedby={
                                                [choosable.length === 0 ? `${supplierId}-hint` : null, supplierError ? errorsId : null]
                                                    .filter(Boolean)
                                                    .join(' ') || undefined
                                            }
                                            onChange={(e) => api.setCell('purchase_orders', row.key, 'award_id', e.target.value)}
                                        >
                                            <option value="">Choose…</option>
                                            {choosable.map((award) => (
                                                <option key={award.key} value={String(award.id)}>
                                                    {text(award.values.supplier_name)}
                                                </option>
                                            ))}
                                        </select>
                                        {choosable.length === 0 && (
                                            <FieldHint hintId={`${supplierId}-hint`}>
                                                {awaitingSave ? 'Saving...' : 'Add an awarded supplier first.'}
                                            </FieldHint>
                                        )}
                                    </EntryCell>
                                    {input('po_number', 'text', 64)}
                                    {input('quantity_ordered', 'decimal')}
                                    {input('ordered_on', 'date')}
                                    {input('quantity_delivered', 'decimal')}
                                    <EntryCell
                                        label={label('delivery_state')}
                                        htmlFor={isOther ? otherId : deliveryId}
                                        width={widthOf('delivery_state')}
                                    >
                                        {isOther ? (
                                            <label className={cn(inputClass(Boolean(otherError)), 'pe-1')}>
                                                <input
                                                    id={otherId}
                                                    type="text"
                                                    maxLength={120}
                                                    placeholder="Other: describe"
                                                    value={text(row.values.delivery_status)}
                                                    className="min-w-0 grow"
                                                    aria-label={`Other Delivery Status, ${rowLabel}`}
                                                    aria-required="true"
                                                    aria-invalid={otherError ? true : undefined}
                                                    aria-describedby={otherError ? errorsId : undefined}
                                                    onChange={(e) => api.setCell('purchase_orders', row.key, 'delivery_status', e.target.value)}
                                                />
                                                <button
                                                    type="button"
                                                    className="btn btn-ghost btn-xs btn-square shrink-0"
                                                    aria-label={`Back to the status list, ${rowLabel}`}
                                                    title="Back to the status list"
                                                    onClick={() => {
                                                        api.setCell('purchase_orders', row.key, 'delivery_state', '');
                                                        api.setCell('purchase_orders', row.key, 'delivery_status', '');
                                                        requestAnimationFrame(() => document.getElementById(deliveryId)?.focus());
                                                    }}
                                                >
                                                    <X aria-hidden="true" className="size-4" />
                                                </button>
                                            </label>
                                        ) : (
                                            <select
                                                id={deliveryId}
                                                className={selectClass(Boolean(stateError))}
                                                value={deliveryState}
                                                aria-label={`Delivery Status, ${rowLabel}`}
                                                aria-required={optional('purchase_orders.delivery_state') === false ? true : undefined}
                                                aria-invalid={stateError ? true : undefined}
                                                aria-describedby={stateError ? errorsId : undefined}
                                                onChange={(e) => {
                                                    api.setCell('purchase_orders', row.key, 'delivery_state', e.target.value);
                                                    // The description belongs to Other only.
                                                    if (e.target.value !== 'OTHER' && text(row.values.delivery_status) !== '') {
                                                        api.setCell('purchase_orders', row.key, 'delivery_status', '');
                                                    }
                                                    // Other: the list becomes the text box, ready to type in.
                                                    if (e.target.value === 'OTHER') {
                                                        requestAnimationFrame(() => document.getElementById(otherId)?.focus());
                                                    }
                                                }}
                                            >
                                                <option value="">Status…</option>
                                                {api.config.options.delivery_state.map((option) => (
                                                    <option key={option.value} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                    </EntryCell>
                                    <EntryCell label={label('delivery_date')} htmlFor={deliveryDateId} width={widthOf('delivery_date')}>
                                        <DatePicker
                                            id={deliveryDateId}
                                            value={text(row.values.delivery_date)}
                                            placeholder="Choose a date"
                                            ariaLabel={`Delivery Date, ${rowLabel}`}
                                            className={ENTRY_INPUT_PAD.date}
                                            // Required once something has been delivered (server: unless Not Delivered or Other).
                                            required={deliveryState !== '' && deliveryState !== 'NOT_DELIVERED' && !isOther}
                                            min={text(row.values.ordered_on) || undefined}
                                            max={today}
                                            invalid={Boolean(dateError)}
                                            describedBy={dateError ? errorsId : undefined}
                                            onChange={(next) => api.setCell('purchase_orders', row.key, 'delivery_date', next)}
                                        />
                                    </EntryCell>
                                    {input('delivery_note', 'text', 100)}
                                    <EntryValue
                                        label="Qty Outstanding"
                                        rowLabel={rowLabel}
                                        value={rowDisplay(api.form.display.rows.purchase_orders, row.id)?.quantity_outstanding ?? '—'}
                                    />
                                </EntryRow>
                            );
                        })}
                    </EntryTable>
                </div>
            </GridRow>

            <TextAreaField
                width="full"
                id={fieldId.requisition('tender_issue_explanation')}
                label="Issues Affecting the Tender Qty"
                optional={optional('tender_issue_explanation')}
                value={text(api.state.requisition.tender_issue_explanation)}
                onChange={(value) => api.setField('tender_issue_explanation', value)}
                error={api.fieldError('tender_issue_explanation')}
            />
        </FieldGrid>
    );
}
