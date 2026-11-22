import { type FormConfig, type Row, type Section } from '@/types/requests';
import { router, usePage } from '@inertiajs/react';
import { CircleAlert, Plus } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { FieldInput } from './field-input';
import { displayValue, type SectionDef } from './fields';

export type EditableRow = Record<string, string | boolean>;

const PAGE_SIZE = 10;

interface RowListProps {
    caseId: string;
    section: Section;
    def: SectionDef;
    /** Rows as last saved; this list is always the server's copy. */
    rows: Row[];
    totals: Record<string, string | null>;
    required: boolean;
    cap?: number;
    config: FormConfig;
}

/** Saved row values as form values (strings and booleans, never null). */
function toEditable(def: SectionDef, row: Row): EditableRow {
    const out: EditableRow = {};
    for (const field of def.columns) {
        const value = row[field.name];
        out[field.name] = field.kind === 'boolean' ? value === true : value === null || value === undefined ? '' : String(value);
    }

    return out;
}

function emptyRow(def: SectionDef): EditableRow {
    const row: EditableRow = {};
    for (const field of def.columns) {
        row[field.name] = field.kind === 'boolean' ? false : '';
    }

    return { ...row, ...def.newRow };
}

/**
 * A compact, paged daisyUI table of one child section. Add and Edit open a
 * native <dialog> (daisyUI modal) with every field; saving or removing a row saves the section straight away (the
 * server replaces the section in full), so nothing here is ever unsaved.
 */
export function RowList({ caseId, section, def, rows, totals, required, cap, config }: RowListProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [page, setPage] = useState(0);
    const [editing, setEditing] = useState<{ index: number; values: EditableRow } | null>(null);
    const [removing, setRemoving] = useState<number | null>(null);
    const [processing, setProcessing] = useState(false);
    const [showErrors, setShowErrors] = useState(false);

    const pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    const atCap = cap !== undefined && rows.length >= cap;
    const rowLabel = def.rowLabel.toLowerCase();
    const headingId = `${section}-heading`;
    const dialogTitleId = `${section}-dialog-title`;
    const dialogRef = useRef<HTMLDialogElement>(null);
    const open = editing !== null;

    // The modal follows `editing`: shown while a row is being added or edited, closed otherwise.
    useEffect(() => {
        const dialog = dialogRef.current;
        if (!dialog) {
            return;
        }
        if (open && !dialog.open) {
            dialog.showModal();
        } else if (!open && dialog.open) {
            dialog.close();
        }
    }, [open]);

    // Keep the page in range when rows are removed.
    useEffect(() => {
        if (page > pages - 1) {
            setPage(pages - 1);
        }
    }, [page, pages]);

    const save = (list: EditableRow[], onDone: () => void) => {
        setProcessing(true);
        setShowErrors(true);
        router.put(
            route('requests.update', caseId),
            { [section]: list },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setShowErrors(false);
                    onDone();
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const saved = () => rows.map((row) => toEditable(def, row));

    const submitDialog = (e: FormEvent) => {
        e.preventDefault();
        if (!editing) {
            return;
        }
        const list = saved();
        const isNew = editing.index >= list.length;
        list[editing.index] = editing.values;
        save(list, () => {
            setEditing(null);
            if (isNew) {
                setPage(Math.floor(editing.index / PAGE_SIZE));
            }
        });
    };

    const remove = (index: number) =>
        save(
            saved().filter((_, i) => i !== index),
            () => setRemoving(null),
        );

    const openNew = () => {
        setShowErrors(false);
        setEditing({ index: rows.length, values: emptyRow(def) });
    };

    const visible = rows.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE);
    const sectionError = showErrors ? errors[section] : undefined;

    const cell = (row: Row, key: string, derived?: boolean) => {
        if (derived) {
            return row.derived?.[key] ?? '—';
        }
        const field = def.columns.find((f) => f.name === key);
        if (!field) {
            return '—';
        }
        if (field.kind === 'boolean') {
            return row[key] === true ? (
                <span className="badge badge-sm badge-soft badge-primary">Yes</span>
            ) : (
                <span className="text-base-content/60">No</span>
            );
        }

        return displayValue(field, row[key], config);
    };

    return (
        <section aria-labelledby={headingId} className="space-y-3">
            <div className="flex flex-wrap items-baseline justify-between gap-3">
                <h2 id={headingId} className="text-lg font-semibold capitalize">
                    {def.title} {required && <span className="text-base-content/60 text-sm font-normal">(required)</span>}
                    <span className="text-base-content/60 ml-2 text-sm font-normal tabular-nums">
                        {rows.length}
                        {cap !== undefined && ` of ${cap}`}
                    </span>
                </h2>
                <button type="button" className="btn btn-sm capitalize" onClick={openNew} disabled={atCap || processing}>
                    <Plus className="size-4" aria-hidden="true" />
                    Add {rowLabel}
                </button>
            </div>

            {sectionError && !editing && (
                <p className="text-error flex items-start gap-1.5 text-sm">
                    <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span className="min-w-0 break-words">{sectionError}</span>
                </p>
            )}

            {rows.length === 0 ? (
                <p className="border-base-300 text-base-content/70 rounded-box border border-dashed p-4 text-sm">No {rowLabel}s yet.</p>
            ) : (
                <div className="border-base-300 bg-base-100 rounded-box max-w-full min-w-0 overflow-x-auto border">
                    <table className="table-sm table">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                {def.summary.map((col) => (
                                    <th key={col.key} scope="col" className="whitespace-nowrap capitalize">
                                        {col.label}
                                    </th>
                                ))}
                                <th scope="col">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((row, i) => {
                                const index = page * PAGE_SIZE + i;
                                const name = `${def.rowLabel} ${index + 1}`;

                                return (
                                    <tr key={row.id ?? index} className="hover:bg-base-200 motion-safe:transition-colors">
                                        <td className="text-base-content/60 tabular-nums">{index + 1}</td>
                                        {def.summary.map((col) => (
                                            <td key={col.key} className="break-words">
                                                {cell(row, col.key, col.derived)}
                                            </td>
                                        ))}
                                        <td>
                                            {removing === index ? (
                                                <div className="flex justify-end gap-1 whitespace-nowrap [&_.btn]:capitalize">
                                                    <button type="button" className="btn btn-sm btn-ghost" onClick={() => setRemoving(null)}>
                                                        Keep
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-error"
                                                        onClick={() => remove(index)}
                                                        disabled={processing}
                                                    >
                                                        Remove<span className="sr-only"> {name}</span>
                                                    </button>
                                                </div>
                                            ) : (
                                                <div className="flex justify-end gap-1 whitespace-nowrap [&_.btn]:capitalize">
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm"
                                                        onClick={() => {
                                                            setShowErrors(false);
                                                            setEditing({ index, values: toEditable(def, row) });
                                                        }}
                                                    >
                                                        Edit<span className="sr-only"> {name}</span>
                                                    </button>
                                                    <button type="button" className="btn btn-sm btn-ghost" onClick={() => setRemoving(index)}>
                                                        Remove<span className="sr-only"> {name}</span>
                                                    </button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {pages > 1 && (
                <nav aria-label={`${def.title} pages`} className="join">
                    <button type="button" className="join-item btn btn-sm capitalize" onClick={() => setPage(page - 1)} disabled={page === 0}>
                        Previous
                    </button>
                    <span className="join-item border-base-300 flex items-center border-y px-3 text-sm tabular-nums">
                        Page {page + 1} of {pages}
                    </span>
                    <button type="button" className="join-item btn btn-sm capitalize" onClick={() => setPage(page + 1)} disabled={page >= pages - 1}>
                        Next Page
                    </button>
                </nav>
            )}

            {def.totals.length > 0 && rows.length > 0 && (
                <dl className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    {def.totals.map(([key, label]) => (
                        <div key={key} className="flex gap-1">
                            <dt className="text-base-content/70">{label}:</dt>
                            <dd className="font-medium tabular-nums">{totals[key] ?? '—'}</dd>
                        </div>
                    ))}
                </dl>
            )}

            <dialog
                ref={dialogRef}
                aria-labelledby={dialogTitleId}
                className="modal"
                onCancel={(e) => {
                    // Esc: keep the dialog open while a save is in flight.
                    e.preventDefault();
                    if (!processing) {
                        setEditing(null);
                    }
                }}
                onClose={() => setEditing(null)}
            >
                <div className="modal-box w-11/12 max-w-3xl">
                    {editing && (
                        <form onSubmit={submitDialog} noValidate className="grid gap-4">
                            <div className="grid gap-1">
                                <h2 id={dialogTitleId} className="text-lg font-semibold capitalize">
                                    {editing.index >= rows.length ? `Add ${rowLabel}` : `Edit ${rowLabel} ${editing.index + 1}`}
                                </h2>
                                <p className="text-base-content/70 text-sm">Saved to your draft as soon as you press Save.</p>
                            </div>

                            {showErrors && (
                                <ul className="text-error space-y-1 text-sm empty:hidden">
                                    {errors[section] && <li>{errors[section]}</li>}
                                    {/* Problems in other saved rows also block the save; name the row. */}
                                    {Object.entries(errors)
                                        .filter(([key]) => key.startsWith(`${section}.`) && !key.startsWith(`${section}.${editing.index}.`))
                                        .map(([key, message]) => (
                                            <li key={key}>
                                                {def.rowLabel} {Number(key.split('.')[1]) + 1}: {message}
                                            </li>
                                        ))}
                                </ul>
                            )}

                            <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                                {def.columns.map((field) => (
                                    <FieldInput
                                        key={field.name}
                                        id={`${section}-${editing.index}-${field.name}`}
                                        field={field}
                                        value={editing.values[field.name] ?? ''}
                                        error={showErrors ? errors[`${section}.${editing.index}.${field.name}`] : undefined}
                                        config={config}
                                        onChange={(value) => setEditing({ ...editing, values: { ...editing.values, [field.name]: value } })}
                                    />
                                ))}
                            </div>

                            <div className="modal-action border-base-300 mt-2 border-t pt-3 [&_.btn]:capitalize">
                                <button type="button" className="btn btn-sm btn-ghost" onClick={() => setEditing(null)} disabled={processing}>
                                    Cancel
                                </button>
                                <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                    {processing && <span className="loading loading-spinner loading-xs" aria-hidden="true" />}
                                    {processing ? 'Saving…' : `Save ${rowLabel}`}
                                </button>
                            </div>
                        </form>
                    )}
                </div>
                <form method="dialog" className="modal-backdrop">
                    <button type="submit" disabled={processing}>
                        close
                    </button>
                </form>
            </dialog>
        </section>
    );
}
