import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type FormConfig, type Row, type Section } from '@/types/requests';
import { router, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
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
 * A compact, paged list of one child section. Add and Edit open a dialog with
 * every field; saving or removing a row saves the section straight away (the
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

    const remove = (index: number) => save(saved().filter((_, i) => i !== index), () => setRemoving(null));

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
            return row[key] === true ? <Badge variant="default">Yes</Badge> : <span className="text-muted-foreground">No</span>;
        }

        return displayValue(field, row[key], config);
    };

    return (
        <section aria-labelledby={headingId} className="space-y-3">
            <div className="flex flex-wrap items-baseline justify-between gap-3">
                <h2 id={headingId} className="text-lg font-semibold">
                    {def.title} {required && <span className="text-muted-foreground text-sm font-normal">(required)</span>}
                    <span className="text-muted-foreground ml-2 text-sm font-normal">
                        {rows.length}
                        {cap !== undefined && ` of ${cap}`}
                    </span>
                </h2>
                <Button type="button" variant="outline" size="sm" onClick={openNew} disabled={atCap || processing}>
                    Add {rowLabel}
                </Button>
            </div>

            {sectionError && !editing && <p className="text-sm text-red-600 dark:text-red-400">{sectionError}</p>}

            {rows.length === 0 ? (
                <p className="text-muted-foreground rounded-md border border-dashed p-4 text-sm">No {rowLabel}s yet.</p>
            ) : (
                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th scope="col" className="px-3 py-2 font-medium">
                                    #
                                </th>
                                {def.summary.map((col) => (
                                    <th key={col.key} scope="col" className="px-3 py-2 font-medium whitespace-nowrap">
                                        {col.label}
                                    </th>
                                ))}
                                <th scope="col" className="px-3 py-2">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((row, i) => {
                                const index = page * PAGE_SIZE + i;
                                const name = `${def.rowLabel} ${index + 1}`;

                                return (
                                    <tr key={row.id ?? index} className="border-t">
                                        <td className="text-muted-foreground px-3 py-2 tabular-nums">{index + 1}</td>
                                        {def.summary.map((col) => (
                                            <td key={col.key} className="px-3 py-2 break-words">
                                                {cell(row, col.key, col.derived)}
                                            </td>
                                        ))}
                                        <td className="px-3 py-2">
                                            {removing === index ? (
                                                <div className="flex justify-end gap-1 whitespace-nowrap">
                                                    <Button type="button" variant="destructive" size="sm" onClick={() => remove(index)} disabled={processing}>
                                                        Remove<span className="sr-only"> {name}</span>
                                                    </Button>
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => setRemoving(null)}>
                                                        Keep
                                                    </Button>
                                                </div>
                                            ) : (
                                                <div className="flex justify-end gap-1 whitespace-nowrap">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => {
                                                            setShowErrors(false);
                                                            setEditing({ index, values: toEditable(def, row) });
                                                        }}
                                                    >
                                                        Edit<span className="sr-only"> {name}</span>
                                                    </Button>
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => setRemoving(index)}>
                                                        Remove<span className="sr-only"> {name}</span>
                                                    </Button>
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
                <nav aria-label={`${def.title} pages`} className="flex items-center gap-2 text-sm">
                    <Button type="button" variant="outline" size="sm" onClick={() => setPage(page - 1)} disabled={page === 0}>
                        Previous
                    </Button>
                    <span>
                        Page {page + 1} of {pages}
                    </span>
                    <Button type="button" variant="outline" size="sm" onClick={() => setPage(page + 1)} disabled={page >= pages - 1}>
                        Next page
                    </Button>
                </nav>
            )}

            {def.totals.length > 0 && rows.length > 0 && (
                <dl className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    {def.totals.map(([key, label]) => (
                        <div key={key} className="flex gap-1">
                            <dt className="text-muted-foreground">{label}:</dt>
                            <dd className="font-medium">{totals[key] ?? '—'}</dd>
                        </div>
                    ))}
                </dl>
            )}

            <Dialog open={editing !== null} onOpenChange={(open) => !open && !processing && setEditing(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    {editing && (
                        <form onSubmit={submitDialog} noValidate className="grid gap-4">
                            <DialogHeader>
                                <DialogTitle>
                                    {editing.index >= rows.length ? `Add ${rowLabel}` : `Edit ${rowLabel} ${editing.index + 1}`}
                                </DialogTitle>
                                <DialogDescription>Saved to your draft as soon as you press Save.</DialogDescription>
                            </DialogHeader>

                            {showErrors && (
                                <ul className="space-y-1 text-sm text-red-600 empty:hidden dark:text-red-400">
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

                            <div className="grid gap-4 sm:grid-cols-2">
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

                            <DialogFooter className="gap-2">
                                <Button type="button" variant="ghost" onClick={() => setEditing(null)} disabled={processing}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving…' : `Save ${rowLabel}`}
                                </Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
