import { FIELD_GRID, FIELD_WIDTH, type FieldWidth } from '@/components/ui/field-width';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

// Labelled native controls for the AD_HOC form (slice F2). 40px high (V2-2b), 44px
// below md. V2-4b: no "(optional)" suffix on labels; a field the server marks
// required carries aria-required for assistive technology. Nothing is marked
// with an asterisk or red text until there is an actual problem.

// V2-4 (design.md sections 4 and 5): labels 15px/600, helper text 13px, inputs on
// --input-bg with the --input border kept (3:1), and an invalid field gets the
// --destructive border with a 3px --destructive-wash halo.

/** The control size and fill for every input and select on the form. */
export const controlHeight = 'h-11 md:h-10 bg-input-bg aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive-wash';

/** The field label (15px/600). */
export const fieldLabel = 'text-label leading-snug font-semibold';

export const textareaClass =
    'border-input bg-input-bg aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive-wash placeholder:text-muted-foreground focus-visible:ring-ring ring-offset-background flex min-h-20 w-full rounded-md border px-3 py-2 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm';

/** Error text, linked from the control with aria-describedby. */
export function FieldError({ id, message }: { id: string; message?: string }) {
    return message ? (
        <p id={id} className="text-destructive-text flex items-start gap-1.5 text-sm">
            <CircleAlert aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <span>{message}</span>
        </p>
    ) : null;
}

/**
 * A message about a whole table or list ("Add at least one quotation."), shown
 * above it: the same icon and --destructive-text as a field's message
 * (MASTER.md section 7). Row and cell problems stay in the row's error list.
 */
export function TableError({ message }: { message?: string }) {
    return message ? (
        <p className="text-destructive-text flex items-start gap-1.5 text-sm">
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

    return (
        <div className={cn('grid min-w-0 content-start gap-2', span(width), className)}>
            <Label htmlFor={id} className={fieldLabel}>
                {label}
            </Label>
            <div className="flex items-center gap-2">
                <Input
                    id={id}
                    type={type}
                    inputMode={inputMode}
                    maxLength={maxLength}
                    value={value}
                    className={controlHeight}
                    aria-required={optional === false ? true : undefined}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy(hint && hintId, error && errorId)}
                    onChange={(e) => onChange(e.target.value)}
                />
                {suffix && <span className="text-muted-foreground shrink-0 text-sm">{suffix}</span>}
            </div>
            {hint && (
                <p id={hintId} className="text-muted-foreground text-helper">
                    {hint}
                </p>
            )}
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

    return (
        <div className={cn('grid min-w-0 gap-2', span(width))}>
            <Label htmlFor={id} className={fieldLabel}>
                {label}
                {srLabelSuffix && <span className="sr-only">{srLabelSuffix}</span>}
            </Label>
            <textarea
                id={id}
                rows={rows}
                maxLength={maxLength}
                value={value}
                className={cn(textareaClass, rows < 3 && 'min-h-0')}
                aria-required={optional === false ? true : undefined}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy(hint && hintId, error && errorId)}
                onChange={(e) => onChange(e.target.value)}
            />
            {hint && (
                <p id={hintId} className="text-muted-foreground text-helper">
                    {hint}
                </p>
            )}
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

/** A native radio group in a fieldset with a legend. The first radio carries the id suffix "-0". */
export function RadioGroupField({ id, legend, options, value, onChange, error, width }: RadioGroupFieldProps) {
    const errorId = `${id}-error`;

    return (
        <fieldset id={id} className={cn('grid min-w-0 gap-2', span(width))} aria-describedby={error ? errorId : undefined}>
            <legend className={cn('mb-1', fieldLabel)}>{legend}</legend>
            {options.map((option, i) => {
                const optionId = `${id}-${i}`;

                return (
                    <div key={option.value} className="flex min-h-11 items-center gap-2 md:min-h-10">
                        <input
                            id={optionId}
                            type="radio"
                            name={id}
                            value={option.value}
                            checked={value === option.value}
                            className="focus-visible:ring-ring size-4 focus-visible:ring-2 focus-visible:ring-offset-2"
                            onChange={() => onChange(option.value)}
                        />
                        <Label htmlFor={optionId} className="font-normal">
                            {option.label}
                        </Label>
                    </div>
                );
            })}
            <FieldError id={errorId} message={error} />
        </fieldset>
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

export function CheckboxField({ id, label, checked, onChange, hint, width }: CheckboxFieldProps) {
    const hintId = `${id}-hint`;

    return (
        <div className={cn('grid min-w-0 gap-1', span(width))}>
            <div className="flex min-h-11 items-center gap-2 md:min-h-10">
                <input
                    id={id}
                    type="checkbox"
                    checked={checked}
                    className="focus-visible:ring-ring size-4 focus-visible:ring-2 focus-visible:ring-offset-2"
                    aria-describedby={hint ? hintId : undefined}
                    onChange={(e) => onChange(e.target.checked)}
                />
                <Label htmlFor={id} className="font-normal">
                    {label}
                </Label>
            </div>
            {hint && (
                <p id={hintId} className="text-muted-foreground text-helper">
                    {hint}
                </p>
            )}
        </div>
    );
}

/** A server-computed value shown read-only: a label and the value (or a dash). */
export function Figure({ label, value, suffix, width }: { label: string; value: ReactNode; suffix?: ReactNode; width?: FieldWidth }) {
    return (
        <div className={cn('grid min-w-0 gap-0.5', span(width))}>
            <dt className="text-muted-foreground text-helper">{label}</dt>
            <dd className="text-sm font-medium tabular-nums">
                {value === null || value === undefined || value === '' ? '—' : value}
                {suffix && value !== null && value !== undefined && value !== '' && (
                    <span className="text-muted-foreground font-normal"> {suffix}</span>
                )}
            </dd>
        </div>
    );
}
