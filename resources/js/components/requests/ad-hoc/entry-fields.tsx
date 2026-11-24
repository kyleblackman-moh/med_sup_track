import { DatePicker } from '@/components/ui/date-picker';
import { ENTRY_WIDTH, EntryCell, type EntryWidth, type RowError, useChecksOffset } from '@/components/ui/entry-table';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';
import { inputClass, textareaClass } from './fields';

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
    /** Date cells: the latest day that can be chosen ('YYYY-MM-DD'). */
    max?: string;
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
    max,
}: EntryInputProps) {
    return (
        <EntryCell label={label} htmlFor={id} width={width} className={className}>
            {kind === 'date' ? (
                <DatePicker
                    id={id}
                    value={value}
                    onChange={onChange}
                    ariaLabel={`${label}, ${rowLabel}`}
                    max={max}
                    required={required}
                    invalid={Boolean(error)}
                    describedBy={error ? errorListId : undefined}
                />
            ) : (
                <input
                    id={id}
                    type="text"
                    // FORM-ERR-1 (E): a keyboard hint only; the server validates and owns the message.
                    inputMode={kind === 'decimal' ? 'decimal' : kind === 'integer' ? 'numeric' : undefined}
                    maxLength={maxLength}
                    value={value}
                    className={inputClass(Boolean(error))}
                    aria-label={`${label}, ${rowLabel}`}
                    aria-required={required ? true : undefined}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? errorListId : undefined}
                    onChange={(e) => onChange(e.target.value)}
                />
            )}
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
                className={textareaClass(Boolean(error), true)}
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
            <span className="text-base-content flex min-h-11 items-center text-sm font-semibold tabular-nums md:min-h-10">
                <span className="sr-only">
                    {label}, {rowLabel}:{' '}
                </span>
                {value}
            </span>
        </EntryCell>
    );
}

/**
 * FORM-COMPACT-1 (2): checkboxes and radios side by side in one cell of a
 * card line (the quotation's Considered, Recommended and Ex Stock), each a
 * native control with its visible label beside it, 16px apart. Level with
 * the line's inputs at the one-line width; wraps below it. 44px targets
 * below md, 40px from md (WCAG 2.2: at least 24px).
 */
export function EntryCheckGroup({ label, children }: { label: string; children: ReactNode }) {
    const offset = useChecksOffset();

    return (
        <div role="group" aria-label={label} className={cn('col-span-full flex flex-wrap items-center gap-x-4 gap-y-1', ENTRY_WIDTH.checks, offset)}>
            {children}
        </div>
    );
}

interface EntryInlineCheckProps {
    id: string;
    label: string;
    rowLabel: string;
    type: 'checkbox' | 'radio';
    /** Radios of one group share a name (Recommended: one quotation across the rows). */
    name?: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}

export function EntryInlineCheck({ id, label, rowLabel, type, name, checked, onChange }: EntryInlineCheckProps) {
    return (
        <label htmlFor={id} className="label text-base-content min-h-11 cursor-pointer gap-2 text-sm font-medium md:min-h-10">
            <input
                id={id}
                type={type}
                name={name}
                checked={checked}
                aria-label={`${label}, ${rowLabel}`}
                // RADIO-1: a radio is the shared daisyUI radio-sm (one Recommended per quotation row, chosen across the rows);
                // a checkbox is the daisyUI checkbox at the same size.
                className={type === 'radio' ? 'radio radio-sm shrink-0' : 'checkbox checkbox-sm shrink-0'}
                onChange={(e) => onChange(e.target.checked)}
            />
            <span className="capitalize">{label}</span>
        </label>
    );
}
