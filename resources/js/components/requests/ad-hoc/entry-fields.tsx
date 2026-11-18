import { EntryCell } from '@/components/ui/entry-table';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';
import { controlHeight } from './fields';

// V2-2: the cells of the form's entry tables (EntryTable). Each control's
// accessible name is "<column>, <row label>", so a screen reader user always
// knows which row they are in; the visible label is the column's (shown above
// the field below the table's breakpoint, as a column header at it).

export function CellError({ id, message }: { id: string; message?: string }) {
    return message ? (
        <p id={id} className="text-destructive text-[13px]">
            {message}
        </p>
    ) : null;
}

interface EntryInputProps {
    id: string;
    /** The column label. */
    label: string;
    rowLabel: string;
    value: string;
    onChange: (value: string) => void;
    kind?: 'text' | 'decimal' | 'integer' | 'date';
    error?: string;
    maxLength?: number;
    className?: string;
    /** V2-4b: the server requires the column whenever a row is entered (aria-required). */
    required?: boolean;
}

export function EntryInput({ id, label, rowLabel, value, onChange, kind = 'text', error, maxLength, className, required }: EntryInputProps) {
    const errorId = `${id}-error`;

    return (
        <EntryCell label={label} htmlFor={id} className={className}>
            <Input
                id={id}
                type={kind === 'date' ? 'date' : 'text'}
                inputMode={kind === 'decimal' ? 'decimal' : kind === 'integer' ? 'numeric' : undefined}
                maxLength={maxLength}
                value={value}
                className={cn(controlHeight, 'w-full min-w-0')}
                aria-label={`${label}, ${rowLabel}`}
                aria-required={required ? true : undefined}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? errorId : undefined}
                onChange={(e) => onChange(e.target.value)}
            />
            <CellError id={errorId} message={error} />
        </EntryCell>
    );
}

/** A server-computed value shown read-only in a row (a dash until the row is saved). */
export function EntryValue({ label, rowLabel, value, className }: { label: string; rowLabel: string; value: ReactNode; className?: string }) {
    return (
        <EntryCell label={label} className={className}>
            <span className="flex min-h-11 items-center text-sm tabular-nums md:min-h-10">
                <span className="sr-only">
                    {label}, {rowLabel}:{' '}
                </span>
                {value}
            </span>
        </EntryCell>
    );
}

interface EntryCheckProps {
    id: string;
    label: string;
    rowLabel: string;
    type: 'checkbox' | 'radio';
    /** Radios of one group share a name. */
    name?: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    className?: string;
}

/** A checkbox or radio in a narrow column, 44px tall below md. */
export function EntryCheck({ id, label, rowLabel, type, name, checked, onChange, className }: EntryCheckProps) {
    return (
        <EntryCell label={label} htmlFor={id} className={className}>
            <span className="flex min-h-11 items-center md:min-h-10">
                <input
                    id={id}
                    type={type}
                    name={name}
                    checked={checked}
                    aria-label={`${label}, ${rowLabel}`}
                    className="accent-primary focus-visible:ring-ring size-4 focus-visible:ring-2 focus-visible:ring-offset-2"
                    onChange={(e) => onChange(e.target.checked)}
                />
            </span>
        </EntryCell>
    );
}
