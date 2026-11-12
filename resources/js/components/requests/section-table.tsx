import { Button } from '@/components/ui/button';
import { type FormConfig, type Row, type Section } from '@/types/requests';
import { FieldInput } from './field-input';
import { type SectionDef } from './fields';

export type EditableRow = Record<string, string | boolean>;

interface SectionTableProps {
    section: Section;
    def: SectionDef;
    rows: EditableRow[];
    savedRows: Row[];
    totals: Record<string, string | null>;
    required: boolean;
    cap?: number;
    config: FormConfig;
    errors: Record<string, string>;
    onChange: (rows: EditableRow[]) => void;
}

/**
 * Editable rows for one child section. Each row is a group of labelled inputs;
 * errors are matched by the server's dotted keys (section.index.field).
 * Server-computed values come from the last save.
 */
export function SectionTable({ section, def, rows, savedRows, totals, required, cap, config, errors, onChange }: SectionTableProps) {
    const sectionError = errors[section];

    const update = (index: number, field: string, value: string | boolean) =>
        onChange(rows.map((row, i) => (i === index ? { ...row, [field]: value } : row)));
    const add = () => onChange([...rows, { ...emptyRow(def), _key: newKey() }]);
    const remove = (index: number) => onChange(rows.filter((_, i) => i !== index));

    return (
        <section aria-labelledby={`${section}-heading`} className="space-y-4">
            <div className="flex items-baseline justify-between gap-4">
                <h2 id={`${section}-heading`} className="text-lg font-semibold">
                    {def.title} {required && <span className="text-muted-foreground text-sm font-normal">(required)</span>}
                </h2>
                <Button type="button" variant="outline" size="sm" onClick={add} disabled={cap !== undefined && rows.length >= cap}>
                    Add {def.rowLabel.toLowerCase()}
                </Button>
            </div>

            {sectionError && <p className="text-sm text-red-600 dark:text-red-400">{sectionError}</p>}
            {rows.length === 0 && <p className="text-muted-foreground text-sm">No rows yet.</p>}

            {rows.map((row, index) => {
                const saved = savedRows[index];

                return (
                    <fieldset key={String(row._key)} className="rounded-lg border p-4">
                        <legend className="px-1 text-sm font-medium">
                            {def.rowLabel} {index + 1}
                        </legend>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {def.columns.map((field) => (
                                <FieldInput
                                    key={field.name}
                                    id={`${section}-${index}-${field.name}`}
                                    field={field}
                                    value={row[field.name] ?? ''}
                                    error={errors[`${section}.${index}.${field.name}`]}
                                    config={config}
                                    onChange={(value) => update(index, field.name, value)}
                                />
                            ))}
                        </div>

                        {def.derived.length > 0 && saved?.derived && (
                            <dl className="text-muted-foreground mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                                {def.derived.map(([key, label]) => (
                                    <div key={key} className="flex gap-1">
                                        <dt>{label}:</dt>
                                        <dd className="font-medium">{saved.derived?.[key] ?? '—'}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}

                        <div className="mt-3 flex justify-end">
                            <Button type="button" variant="ghost" size="sm" onClick={() => remove(index)}>
                                Remove {def.rowLabel.toLowerCase()} {index + 1}
                            </Button>
                        </div>
                    </fieldset>
                );
            })}

            {def.totals.length > 0 && savedRows.length > 0 && (
                <dl className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    {def.totals.map(([key, label]) => (
                        <div key={key} className="flex gap-1">
                            <dt className="text-muted-foreground">{label}:</dt>
                            <dd className="font-medium">{totals[key] ?? '—'}</dd>
                        </div>
                    ))}
                </dl>
            )}
            {def.derived.length + def.totals.length > 0 && <p className="text-muted-foreground text-xs">Totals update when you save.</p>}
        </section>
    );
}

let counter = 0;

/** A client-only key for React lists; removed before the form is sent. */
export function newKey(): string {
    counter += 1;

    return `new-${counter}`;
}

function emptyRow(def: SectionDef): EditableRow {
    const row: EditableRow = {};
    for (const field of def.columns) {
        row[field.name] = field.kind === 'boolean' ? false : '';
    }

    return { ...row, ...def.newRow };
}
