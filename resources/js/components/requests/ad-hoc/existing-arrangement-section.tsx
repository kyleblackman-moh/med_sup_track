import { EntryCell, EntryRow, EntryTable, type EntryColumn } from '@/components/ui/entry-table';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { collectRowErrors, EntryInput, EntryValue } from './entry-fields';
import { controlHeight, FieldGrid, GridRow, TextAreaField } from './fields';
import { rowDisplay, text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { AddRowButton, RemoveRowButton, RowsNote } from './row-controls';

const selectClass =
    'border-input focus-visible:ring-ring ring-offset-background w-full min-w-0 rounded-md border px-3 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm';

/**
 * V2-2 section "Existing approved arrangement": the orders placed against the
 * awarded suppliers (each picks its supplier from the SAVED award rows, which
 * have an id), the server's quantity outstanding per order, and one text box
 * for issues affecting the tender quantity.
 */
export function ExistingArrangementSection({ api }: { api: FormApi }) {
    const rows = api.state.rows.purchase_orders;
    const awards = api.state.rows.tender_awards;
    const choosable = awards.filter((row) => row.id !== null && text(row.values.supplier_name).trim() !== '');
    const awaitingSave = awards.some((row) => row.id === null && text(row.values.supplier_name).trim() !== '');
    const optional = (name: string) => api.optional('existing_arrangement', name);
    const cap = api.config.row_caps.purchase_orders;
    const tableError = api.tableError('purchase_orders');

    const columns: EntryColumn[] = [
        { key: 'award_id', label: 'Supplier', width: 'sm' },
        { key: 'po_number', label: 'PO Number' },
        { key: 'quantity_ordered', label: 'Quantity Ordered', width: 'xs' },
        { key: 'ordered_on', label: 'Date Ordered', width: 'sm' },
        { key: 'quantity_delivered', label: 'Quantity Delivered', width: 'xs' },
        { key: 'delivery', label: 'Delivery Date / Status', width: 'sm' },
        { key: 'quantity_outstanding', label: 'Quantity Outstanding', width: 'value' },
    ];
    const label = (key: string) => columns.find((c) => c.key === key)?.label ?? key;

    return (
        <FieldGrid>
            <GridRow>
                <div id={fieldId.table('purchase_orders')} tabIndex={-1} className="grid gap-2 outline-none">
                    <p className="text-muted-foreground text-[13px]">One supplier can have several orders.</p>
                    {tableError && <p className="text-destructive text-sm">{tableError}</p>}
                    <EntryTable
                        caption="Orders"
                        columns={columns}
                        breakpoint="66rem"
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
                                    'delivery_eta',
                                    'delivery_status',
                                ],
                                errorOf,
                            );
                            const etaError = errorOf('delivery_eta');
                            const statusError = errorOf('delivery_status');
                            const input = (name: string, kind: 'text' | 'decimal' | 'date' = 'text', maxLength?: number) => (
                                <EntryInput
                                    id={fieldId.cell('purchase_orders', row.key, name)}
                                    label={label(name)}
                                    rowLabel={rowLabel}
                                    kind={kind}
                                    width={kind === 'decimal' ? 'xs' : kind === 'date' ? 'sm' : 'md'}
                                    maxLength={maxLength}
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
                            const deliveryId = fieldId.cell('purchase_orders', row.key, 'delivery_status');

                            return (
                                <EntryRow
                                    key={row.key}
                                    label={rowLabel}
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
                                    <EntryCell label={label('award_id')} htmlFor={supplierId} width="sm">
                                        <select
                                            id={supplierId}
                                            className={cn(selectClass, controlHeight)}
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
                                            <p id={`${supplierId}-hint`} className="text-muted-foreground text-[13px]">
                                                {awaitingSave ? 'Saving...' : 'Add an awarded supplier first.'}
                                            </p>
                                        )}
                                    </EntryCell>
                                    {input('po_number', 'text', 64)}
                                    {input('quantity_ordered', 'decimal')}
                                    {input('ordered_on', 'date')}
                                    {input('quantity_delivered', 'decimal')}
                                    <EntryCell label={label('delivery')} htmlFor={`${deliveryId}-date`} width="sm">
                                        <div className="grid gap-1">
                                            <Input
                                                id={`${deliveryId}-date`}
                                                type="date"
                                                value={text(row.values.delivery_eta)}
                                                className={cn(controlHeight, 'w-full min-w-0')}
                                                aria-label={`Delivery Date, ${rowLabel}`}
                                                aria-required={optional('purchase_orders.delivery_eta') === false ? true : undefined}
                                                aria-invalid={etaError ? true : undefined}
                                                aria-describedby={etaError ? errorsId : undefined}
                                                onChange={(e) => api.setCell('purchase_orders', row.key, 'delivery_eta', e.target.value)}
                                            />
                                            <Input
                                                id={deliveryId}
                                                type="text"
                                                maxLength={120}
                                                value={text(row.values.delivery_status)}
                                                placeholder="Status"
                                                className={cn(controlHeight, 'w-full min-w-0')}
                                                aria-label={`Delivery Status, ${rowLabel}`}
                                                aria-required={optional('purchase_orders.delivery_status') === false ? true : undefined}
                                                aria-invalid={statusError ? true : undefined}
                                                aria-describedby={statusError ? errorsId : undefined}
                                                onChange={(e) => api.setCell('purchase_orders', row.key, 'delivery_status', e.target.value)}
                                            />
                                        </div>
                                    </EntryCell>
                                    <EntryValue
                                        label="Quantity Outstanding"
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
                label="Issues Affecting the Tender Quantity"
                optional={optional('tender_issue_explanation')}
                value={text(api.state.requisition.tender_issue_explanation)}
                onChange={(value) => api.setField('tender_issue_explanation', value)}
                error={api.fieldError('tender_issue_explanation')}
            />
        </FieldGrid>
    );
}
