import { EntryRow, EntryTable, type EntryColumn } from '@/components/ui/entry-table';
import { collectRowErrors, EntryInput, EntryTextArea } from './entry-fields';
import { TableError } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { AddRowButton, RemoveRowButton, RowsNote } from './row-controls';

/** Text limits of the columns (RequisitionRules, variation_rows). */
const MAX = { variation_type: 100, existing_detail: 500, proposed_detail: 500, effective_detail: 100 };

/**
 * V2-5 section "Details of Variation Requested", shown only when the server
 * marks it visible (the previous-request answer is Yes, any type). One entry
 * per variation, mirroring the awarded supplier table: one line per entry once
 * the card is 45rem wide (FORM-ERR-1), wrapping below; the existing and proposed details are 2-row textareas;
 * "Add Another Variation" at the bottom. Rows are saved by the autosave, on
 * their row id, like the award rows.
 */
export function VariationSection({ api }: { api: FormApi }) {
    const rows = api.state.rows.variation_rows;
    const optional = (name: string) => api.optional('variation_details', `variation_rows.${name}`);
    const cap = api.config.row_caps.variation_rows;
    const tableError = api.tableError('variation_rows');

    const columns: EntryColumn[] = [
        { key: 'variation_type', label: 'Variation Type' },
        { key: 'existing_detail', label: 'Existing Approved Detail', width: 'lg' },
        { key: 'proposed_detail', label: 'Proposed Revised Detail', width: 'lg' },
        { key: 'effective_detail', label: 'Effective Date / Application P.O.' },
    ];
    const label = (key: string) => columns.find((c) => c.key === key)?.label ?? key;
    const width = (key: string) => columns.find((c) => c.key === key)?.width;

    return (
        <div id={fieldId.table('variation_rows')} tabIndex={-1} className="grid gap-2 outline-none">
            <TableError message={tableError || undefined} />
            <EntryTable
                caption="Details of Variation Requested"
                columns={columns}
                breakpoint="45rem"
                narrowRowLabel
                empty={rows.length === 0 && <RowsNote>No variation yet.</RowsNote>}
                addAction={
                    <AddRowButton
                        label="Add Another Variation"
                        variant="text"
                        disabled={rows.length >= cap}
                        cap={cap}
                        onClick={() => api.addRow('variation_rows')}
                    />
                }
            >
                {rows.map((row, index) => {
                    const rowLabel = `Variation Row ${index + 1}`;
                    // FORM-ERR-1 (A): every message of the row in one list under it, in column order.
                    const errorsId = fieldId.rowErrors('variation_rows', row.key);
                    const errors = collectRowErrors(['id', ...columns.map((c) => c.key)], (name) =>
                        api.cellError('variation_rows', row, index, name),
                    );
                    const cell = (name: keyof typeof MAX) => ({
                        id: fieldId.cell('variation_rows', row.key, name),
                        label: label(name),
                        rowLabel,
                        maxLength: MAX[name],
                        value: text(row.values[name]),
                        onChange: (value: string) => api.setCell('variation_rows', row.key, name, value),
                        error: api.cellError('variation_rows', row, index, name),
                        errorListId: errorsId,
                        required: optional(name) === false,
                        width: width(name),
                    });

                    return (
                        <EntryRow
                            key={row.key}
                            label={rowLabel}
                            short={String(index + 1)}
                            errors={errors}
                            errorsId={errorsId}
                            remove={
                                <RemoveRowButton
                                    label={`Remove Variation Row ${index + 1}`}
                                    onClick={() => api.removeRow('variation_rows', row.key)}
                                />
                            }
                        >
                            <EntryInput {...cell('variation_type')} />
                            <EntryTextArea {...cell('existing_detail')} rows={2} />
                            <EntryTextArea {...cell('proposed_detail')} rows={2} />
                            <EntryInput {...cell('effective_detail')} />
                        </EntryRow>
                    );
                })}
            </EntryTable>
        </div>
    );
}
