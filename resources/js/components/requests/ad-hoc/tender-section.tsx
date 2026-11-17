import { cn } from '@/lib/utils';
import { controlHeight, Figure, RadioGroupField, TextAreaField, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId, serverRow } from './form-state';
import { CellError, CellLabel, RowTable, type CellContext, type Column } from './row-table';

const selectClass =
    'border-input bg-background focus-visible:ring-ring ring-offset-background w-full min-w-0 rounded-md border px-3 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm';

/** Section 2, Tender background, with the award and order tables when something was awarded. */
export function TenderSection({ api }: { api: FormApi }) {
    const { state, detail, config } = api;
    const outcome = text(state.requisition.tender_outcome);
    const awarded = outcome !== '' && outcome !== config.not_awarded;
    const description = text(state.requisition.item_description).trim();

    const awards = state.rows.tender_awards;
    // Orders pick their supplier from SAVED award rows (they need the award's id).
    const choosable = awards.filter((row) => row.id !== null && text(row.values.supplier_name).trim() !== '');
    const awaitingSave = awards.some((row) => row.id === null && text(row.values.supplier_name).trim() !== '');

    const awardColumns: Column[] = [
        { name: 'supplier_name', label: 'Supplier', kind: 'text' },
        { name: 'manufacturer', label: 'Manufacturer', kind: 'text', optional: true },
        { name: 'brand', label: 'Brand', kind: 'text', optional: true },
        { name: 'quantity_awarded', label: 'Quantity awarded', kind: 'decimal' },
        {
            name: 'percentage',
            label: '% awarded',
            kind: 'computed',
            computed: (row) => {
                const value = serverRow(detail, 'tender_awards', row.id)?.derived?.percentage;
                return value ? `${value}%` : '—';
            },
        },
        { name: 'unit_cost', label: 'Unit cost', kind: 'decimal' },
        {
            name: 'total',
            label: 'Total cost',
            kind: 'computed',
            computed: (row) => serverRow(detail, 'tender_awards', row.id)?.derived?.total ?? '—',
        },
    ];

    const supplierCell = ({ row, id, rowText, error, errorId }: CellContext) => {
        const hintId = `${id}-hint`;
        const disabled = choosable.length === 0;
        const current = text(row.values.award_id);
        const valid = choosable.some((award) => String(award.id) === current);

        return (
            <div className="grid min-w-0 content-start gap-1.5">
                <CellLabel htmlFor={id} text="Supplier" rowText={rowText} />
                <select
                    id={id}
                    className={cn(selectClass, controlHeight)}
                    value={valid ? current : ''}
                    disabled={disabled}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={[disabled ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined}
                    onChange={(e) => api.setCell('purchase_orders', row.key, 'award_id', e.target.value)}
                >
                    <option value="">Choose…</option>
                    {choosable.map((award) => (
                        <option key={award.key} value={String(award.id)}>
                            {text(award.values.supplier_name)}
                        </option>
                    ))}
                </select>
                {disabled && (
                    <p id={hintId} className="text-muted-foreground text-xs">
                        {awaitingSave ? 'Saving...' : 'Add a supplier above'}
                    </p>
                )}
                <CellError id={errorId} message={error} />
            </div>
        );
    };

    // Two grid items, one per control, each with its own label.
    const deliveryCell = ({ row, id, rowText }: CellContext) => (
        <>
            <div className="grid min-w-0 content-start gap-1.5">
                <CellLabel htmlFor={`${id}-date`} text="Delivery date" rowText={rowText} optional />
                <input
                    id={`${id}-date`}
                    type="date"
                    value={text(row.values.delivery_eta)}
                    className={cn(selectClass, controlHeight)}
                    onChange={(e) => api.setCell('purchase_orders', row.key, 'delivery_eta', e.target.value)}
                />
            </div>
            <div className="grid min-w-0 content-start gap-1.5">
                <CellLabel htmlFor={id} text="Delivery status" rowText={rowText} optional />
                <input
                    id={id}
                    type="text"
                    maxLength={120}
                    value={text(row.values.delivery_status)}
                    className={cn(selectClass, controlHeight)}
                    onChange={(e) => api.setCell('purchase_orders', row.key, 'delivery_status', e.target.value)}
                />
            </div>
        </>
    );

    const orderColumns: Column[] = [
        { name: 'award_id', label: 'Supplier', kind: 'custom', render: supplierCell },
        { name: 'po_number', label: 'PO #', kind: 'text' },
        { name: 'quantity_ordered', label: 'Quantity ordered', kind: 'decimal' },
        { name: 'ordered_on', label: 'Date ordered', kind: 'date', optional: true },
        { name: 'quantity_delivered', label: 'Quantity delivered', kind: 'decimal', optional: true },
        {
            name: 'quantity_outstanding',
            label: 'Quantity outstanding',
            kind: 'computed',
            computed: (row) => serverRow(detail, 'purchase_orders', row.id)?.derived?.quantity_outstanding ?? '—',
        },
        { name: 'delivery_status', label: 'Delivery date / status', kind: 'custom', render: deliveryCell, optional: true },
    ];

    return (
        <div className="grid grid-cols-1 gap-6">
            <p className="text-sm">
                <span className="text-muted-foreground">Item: </span>
                {description || <span className="text-muted-foreground">Not described yet (section 1)</span>}
            </p>

            <TextField
                id={fieldId.requisition('tender_reference')}
                label="Tender period / reference"
                maxLength={120}
                value={text(state.requisition.tender_reference)}
                onChange={(value) => api.setField('tender_reference', value)}
                error={api.fieldError('tender_reference')}
                hint="For example TENDER-2026-14 (2026 to 2027)."
            />

            <RadioGroupField
                id={fieldId.requisition('tender_outcome')}
                legend="Outcome of the tender"
                options={config.options.tender_outcome}
                value={outcome}
                onChange={(value) => api.setField('tender_outcome', value)}
                error={api.fieldError('tender_outcome')}
            />

            {awarded && (
                <>
                    <RowTable
                        section="tender_awards"
                        title="Awards"
                        rowLabel="Award row"
                        addLabel="Add supplier"
                        rows={awards}
                        columns={awardColumns}
                        cap={config.row_caps.tender_awards}
                        onChange={(key, name, value) => api.setCell('tender_awards', key, name, value)}
                        onAdd={() => api.addRow('tender_awards')}
                        onRemove={(key) => api.removeRow('tender_awards', key)}
                        cellError={(row, index, name) => api.cellError('tender_awards', row, index, name)}
                        tableError={api.tableError('tender_awards')}
                        above={
                            <dl>
                                <Figure label="Number of suppliers awarded" value={detail.figures?.suppliers_awarded ?? 0} />
                            </dl>
                        }
                    />

                    <RowTable
                        section="purchase_orders"
                        title="Orders"
                        rowLabel="Order"
                        addLabel="Add order"
                        rows={state.rows.purchase_orders}
                        columns={orderColumns}
                        cap={config.row_caps.purchase_orders}
                        onChange={(key, name, value) => api.setCell('purchase_orders', key, name, value)}
                        onAdd={() => api.addRow('purchase_orders')}
                        onRemove={(key) => api.removeRow('purchase_orders', key)}
                        cellError={(row, index, name) => api.cellError('purchase_orders', row, index, name)}
                        tableError={api.tableError('purchase_orders')}
                        above={<p className="text-muted-foreground text-xs">Orders are optional. One supplier can have several orders.</p>}
                    />

                    <TextAreaField
                        id={fieldId.requisition('tender_issue_explanation')}
                        label={'Issues affecting the tender quantity'}
                        optional
                        value={text(state.requisition.tender_issue_explanation)}
                        onChange={(value) => api.setField('tender_issue_explanation', value)}
                        error={api.fieldError('tender_issue_explanation')}
                    />
                </>
            )}
            {!awarded && outcome === '' && (
                <p className="text-muted-foreground text-sm">The award and order tables appear when the tender was partly or fully awarded.</p>
            )}
        </div>
    );
}
