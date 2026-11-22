import InputError from '@/components/input-error';
import { FIELD_GRID, FIELD_WIDTH, type FieldWidth } from '@/components/ui/field-width';
import { RadioGroup } from '@/components/ui/radio-group';
import { cn } from '@/lib/utils';
import { type OptionList } from '@/types/requests';
import { CircleAlert } from 'lucide-react';
import { type ElementType, type ReactNode } from 'react';

/**
 * FORM-COMPACT-1 (1): a group of stacked fields, 1 to 12 columns by the
 * card's width (FIELD_GRID). Every child takes a width from the one scale:
 * a field through its `width` prop, anything else (a table, a note) through
 * <GridRow>. `as="dl"` for a group of server figures.
 */
export function FieldGrid({ children, as: Tag = 'div' }: { children: ReactNode; as?: ElementType }) {
    return (
        <div className="@container min-w-0">
            <Tag className={FIELD_GRID}>{children}</Tag>
        </div>
    );
}

/** A FieldGrid child that is not a field (a table, a helper line, a notice): the whole row by default. */
export function GridRow({ width = 'full', children, className }: { width?: FieldWidth; children: ReactNode; className?: string }) {
    return <div className={cn('min-w-0', FIELD_WIDTH[width], className)}>{children}</div>;
}

/** The grid span of a field (empty when the field is not in a FieldGrid). */
const span = (width?: FieldWidth) => (width ? FIELD_WIDTH[width] : undefined);

// Labelled native controls for the AD_HOC form (slice F2), daisyUI markup
// (Blueprint: fieldset, label, input, select, textarea, checkbox). 40px high
// (the daisyUI field size), 44px below md. V2-4b: no "(optional)" suffix on
// labels; a field the server marks required carries aria-required for
// assistive technology. Nothing is marked with an asterisk or red text until
// there is an actual problem; then the control takes the daisyUI error colour.

/** 44px touch target below md; the daisyUI field height (40px) from md. */
const touchHeight = 'max-md:h-11';

/** The daisyUI text input of the form (also the entry rows): full width of its cell, the error colour when invalid. */
export function inputClass(invalid?: boolean): string {
    return cn('input w-full min-w-0', touchHeight, invalid && 'input-error');
}

/** The daisyUI select of the form. */
export function selectClass(invalid?: boolean): string {
    return cn('select w-full min-w-0', touchHeight, invalid && 'select-error');
}

/** The daisyUI textarea of the form. `short` drops the minimum height (2-row cells). */
export function textareaClass(invalid?: boolean, short?: boolean): string {
    return cn('textarea w-full min-w-0', short && 'min-h-0', invalid && 'textarea-error');
}

/** The field label (daisyUI fieldset-legend on a real <label>): capitalised, as every label on the form. */
export const fieldLabelClass = 'fieldset-legend py-0 capitalize';

/** Error text, linked from the control with aria-describedby (the shared InputError). */
export function FieldError({ id, message }: { id: string; message?: string }) {
    return <InputError id={id} message={message} />;
}

/** Helper text under a control (13px, muted, sentence case). Give it the id the control names in aria-describedby. */
export function FieldHint({ hintId, children }: { hintId?: string; children: ReactNode }) {
    return (
        <p id={hintId} className="text-muted-foreground text-helper">
            {children}
        </p>
    );
}

/**
 * A message about a whole table or list ("Add at least one quotation."), shown
 * above it: the same icon and error colour as a field's message
 * (MASTER.md section 7). Row and cell problems stay in the row's error list.
 */
export function TableError({ message }: { message?: string }) {
    return message ? (
        <p className="text-error flex items-start gap-1.5 text-sm">
            <CircleAlert aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <span className="min-w-0 break-words">{message}</span>
        </p>
    ) : null;
}

function describedBy(...ids: (string | false | undefined)[]) {
    return ids.filter(Boolean).join(' ') || undefined;
}

interface TextFieldProps {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    optional?: boolean;
    hint?: string;
    error?: string;
    inputMode?: 'decimal' | 'numeric' | 'text';
    type?: 'text' | 'date';
    maxLength?: number;
    /** Static text after the control, for example the unit of measure. */
    suffix?: ReactNode;
    /** FORM-COMPACT-1: the field's width on the scale (its span in a FieldGrid). */
    width?: FieldWidth;
    className?: string;
}

export function TextField({
    id,
    label,
    value,
    onChange,
    optional,
    hint,
    error,
    inputMode,
    type = 'text',
    maxLength,
    suffix,
    width,
    className,
}: TextFieldProps) {
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;

    const control = (
        <input
            id={id}
            type={type}
            inputMode={inputMode}
            maxLength={maxLength}
            value={value}
            // With a suffix the daisyUI input is the wrapping label; the native input only grows inside it.
            className={suffix ? 'min-w-0 grow' : inputClass(Boolean(error))}
            aria-required={optional === false ? true : undefined}
            aria-invalid={error ? true : undefined}
            aria-describedby={describedBy(hint && hintId, error && errorId)}
            onChange={(e) => onChange(e.target.value)}
        />
    );

    return (
        <div className={cn('fieldset min-w-0 content-start gap-2 py-0', span(width), className)}>
            <label htmlFor={id} className={fieldLabelClass}>
                {label}
            </label>
            {suffix ? (
                <label className={inputClass(Boolean(error))}>
                    {control}
                    <span className="label">{suffix}</span>
                </label>
            ) : (
                control
            )}
            {hint && <FieldHint hintId={hintId}>{hint}</FieldHint>}
            <FieldError id={errorId} message={error} />
        </div>
    );
}

interface TextAreaFieldProps {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    optional?: boolean;
    hint?: string;
    error?: string;
    maxLength?: number;
    /** Visible rows (default 3). */
    rows?: number;
    /**
     * FORM-ERR-1: a field inside a table row (the quotation's reason) shows its
     * message in the row's error list, not under itself: the list's id.
     */
    errorListId?: string;
    /** FORM-COMPACT-1: the field's width on the scale. */
    width?: FieldWidth;
    /** Read after the label by screen readers only (the row a reason belongs to). */
    srLabelSuffix?: string;
}

export function TextAreaField({
    id,
    label,
    value,
    onChange,
    optional,
    hint,
    error,
    maxLength,
    rows = 3,
    errorListId,
    width,
    srLabelSuffix,
}: TextAreaFieldProps) {
    const hintId = `${id}-hint`;
    const errorId = errorListId ?? `${id}-error`;
    const labelId = `${id}-label`;

    return (
        <div className={cn('fieldset min-w-0 gap-2 py-0', span(width))}>
            <label id={labelId} htmlFor={id} className={fieldLabelClass}>
                {label}
                {srLabelSuffix && <span className="sr-only">{srLabelSuffix}</span>}
            </label>
            <textarea
                id={id}
                rows={rows}
                maxLength={maxLength}
                value={value}
                aria-labelledby={labelId}
                className={textareaClass(Boolean(error), rows < 3)}
                aria-required={optional === false ? true : undefined}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy(hint && hintId, error && errorId)}
                onChange={(e) => onChange(e.target.value)}
            />
            {hint && <FieldHint hintId={hintId}>{hint}</FieldHint>}
            {!errorListId && <FieldError id={errorId} message={error} />}
        </div>
    );
}

interface RadioGroupFieldProps {
    /** Used for the fieldset id (the jump-link target) and the radio name. */
    id: string;
    legend: string;
    options: OptionList;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    /** FORM-COMPACT-1: the field's width on the scale. */
    width?: FieldWidth;
}

/**
 * A native radio group in a fieldset with a legend: the shared RadioGroup
 * (RADIO-1, options stacked). The first radio carries the id suffix "-0".
 */
export function RadioGroupField({ id, legend, options, value, onChange, error, width }: RadioGroupFieldProps) {
    const errorId = `${id}-error`;

    return (
        <RadioGroup
            id={id}
            name={id}
            legend={legend}
            options={options}
            value={value}
            onChange={onChange}
            optionId={(_option, i) => `${id}-${i}`}
            describedBy={error ? errorId : undefined}
            className={span(width)}
        >
            <FieldError id={errorId} message={error} />
        </RadioGroup>
    );
}

interface CheckboxFieldProps {
    id: string;
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    hint?: string;
    /** FORM-COMPACT-1: the field's width on the scale. */
    width?: FieldWidth;
}

/** A daisyUI checkbox inside its clickable label (44px row below md, 40px from md). */
export function CheckboxField({ id, label, checked, onChange, hint, width }: CheckboxFieldProps) {
    const hintId = `${id}-hint`;

    return (
        <div className={cn('fieldset min-w-0 gap-1 py-0', span(width))}>
            <label htmlFor={id} className="label text-base-content min-h-11 cursor-pointer gap-2 text-sm md:min-h-10">
                <input
                    id={id}
                    type="checkbox"
                    checked={checked}
                    className="checkbox checkbox-sm"
                    aria-describedby={hint ? hintId : undefined}
                    onChange={(e) => onChange(e.target.checked)}
                />
                <span className="capitalize">{label}</span>
            </label>
            {hint && <FieldHint hintId={hintId}>{hint}</FieldHint>}
        </div>
    );
}

/** A server-computed value shown read-only: a label and the value (or a dash). */
export function Figure({ label, value, suffix, width }: { label: string; value: ReactNode; suffix?: ReactNode; width?: FieldWidth }) {
    const empty = value === null || value === undefined || value === '';

    return (
        <div className={cn('grid min-w-0 gap-0.5', span(width))}>
            <dt className="text-base-content/70 text-sm capitalize">{label}</dt>
            <dd className="text-base-content text-sm font-semibold tabular-nums">
                {empty ? '—' : value}
                {suffix && !empty && <span className="text-base-content/70 font-normal"> {suffix}</span>}
            </dd>
        </div>
    );
}
