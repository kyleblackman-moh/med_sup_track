import { EntryCell, type EntryWidth, type RowError } from '@/components/ui/entry-table';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';
import { controlHeight, textareaClass } from './fields';

// V2-2: the cells of the form's entry tables (EntryTable). Each control's
// accessible name is "<column>, <row label>", so a screen reader user always
// knows which row they are in; the visible label is the column's (shown above
// the field below the table's breakpoint, as a column header at it).
//
// FORM-ERR-1 (A): a cell never shows an error message. A failing control gets
// the error border and aria-invalid, and aria-describedby points at the row's
// one full-width error list (EntryRow errors, RowErrorList).

/** The row's errors, in column order, for EntryRow's `errors`. */
export function collectRowErrors(fields: string[], errorOf: (field: string) => string | undefined): RowError[] {
    return fields.flatMap((field) => {
        const message = errorOf(field);

        return message ? [{ field, message }] : [];
    });
}

interface EntryInputProps {
    id: string;
    /** The column label. */
    label: string;
    rowLabel: string;
    value: string;
    onChange: (value: string) => void;
    kind?: 'text' | 'decimal' | 'integer' | 'date';
    /** The field's problem: marks the control invalid (the message itself is in the row's list). */
    error?: string;
    /** The id of the row's error list (EntryRow errorsId). */
    errorListId?: string;
    maxLength?: number;
    /** FORM-ERR-1 (B): the column's width (EntryColumn width). */
    width?: EntryWidth;
    className?: string;
    /** V2-4b: the server requires the column whenever a row is entered (aria-required). */
    required?: boolean;
}

export function EntryInput({
    id,
    label,
    rowLabel,
    value,
    onChange,
    kind = 'text',
    error,
    errorListId,
    maxLength,
    width,
    className,
    required,
}: EntryInputProps) {
    return (
        <EntryCell label={label} htmlFor={id} width={width} className={className}>
            <Input
                id={id}
                type={kind === 'date' ? 'date' : 'text'}
                // FORM-ERR-1 (E): a keyboard hint only; the server validates and owns the message.
                inputMode={kind === 'decimal' ? 'decimal' : kind === 'integer' ? 'numeric' : undefined}
                maxLength={maxLength}
                value={value}
                className={cn(controlHeight, 'w-full min-w-0')}
                aria-label={`${label}, ${rowLabel}`}
                aria-required={required ? true : undefined}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? errorListId : undefined}
                onChange={(e) => onChange(e.target.value)}
            />
        </EntryCell>
    );
}

interface EntryTextAreaProps {
    id: string;
    label: string;
    rowLabel: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    errorListId?: string;
    maxLength?: number;
    /** Visible rows (V2-5: 2 for the variation details). */
    rows?: number;
    /** FORM-ERR-1 (B): the column's width (EntryColumn width). */
    width?: EntryWidth;
    className?: string;
    required?: boolean;
}

/** V2-5: a short textarea cell, named "<column>, <row label>" like EntryInput. */
export function EntryTextArea({
    id,
    label,
    rowLabel,
    value,
    onChange,
    error,
    errorListId,
    maxLength,
    rows = 2,
    width,
    className,
    required,
}: EntryTextAreaProps) {
    return (
        <EntryCell label={label} htmlFor={id} width={width} className={className}>
            <textarea
                id={id}
                rows={rows}
                maxLength={maxLength}
                value={value}
                className={cn(textareaClass, 'min-h-0 w-full min-w-0')}
                aria-label={`${label}, ${rowLabel}`}
                aria-required={required ? true : undefined}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? errorListId : undefined}
                onChange={(e) => onChange(e.target.value)}
            />
        </EntryCell>
    );
}

/** A server-computed value shown read-only in a row (a dash until the row is saved). */
export function EntryValue({
    label,
    rowLabel,
    value,
    width = 'value',
    className,
}: {
    label: string;
    rowLabel: string;
    value: ReactNode;
    width?: EntryWidth;
    className?: string;
}) {
    return (
        <EntryCell label={label} width={width} className={className}>
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
    /** FORM-ERR-1 (B): the column's width (EntryColumn width). */
    width?: EntryWidth;
    className?: string;
}

/** A checkbox or radio in a narrow column, 44px tall below md. */
export function EntryCheck({ id, label, rowLabel, type, name, checked, onChange, width = 'check', className }: EntryCheckProps) {
    return (
        <EntryCell label={label} htmlFor={id} width={width} className={className}>
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
