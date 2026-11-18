import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type OptionList } from '@/types/requests';
import { type ReactNode } from 'react';

// Labelled native controls for the AD_HOC form (slice F2). 40px high (V2-2b), 44px
// below md. Only optional fields say "(optional)"; nothing is marked with an
// asterisk or red text until there is an actual problem.

export const controlHeight = 'h-11 md:h-10';

const textareaClass =
    'border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring ring-offset-background flex min-h-20 w-full rounded-md border px-3 py-2 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm';

export function Optional() {
    return <span className="text-muted-foreground font-normal"> (optional)</span>;
}

/** Error text, linked from the control with aria-describedby. */
export function FieldError({ id, message }: { id: string; message?: string }) {
    return message ? (
        <p id={id} className="text-destructive text-sm">
            {message}
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
    className,
}: TextFieldProps) {
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;

    return (
        <div className={cn('grid content-start gap-2', className)}>
            <Label htmlFor={id}>
                {label}
                {optional && <Optional />}
            </Label>
            <div className="flex items-center gap-2">
                <Input
                    id={id}
                    type={type}
                    inputMode={inputMode}
                    maxLength={maxLength}
                    value={value}
                    className={controlHeight}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy(hint && hintId, error && errorId)}
                    onChange={(e) => onChange(e.target.value)}
                />
                {suffix && <span className="text-muted-foreground shrink-0 text-sm">{suffix}</span>}
            </div>
            {hint && (
                <p id={hintId} className="text-muted-foreground text-[13px]">
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
}

export function TextAreaField({ id, label, value, onChange, optional, hint, error, maxLength, rows = 3 }: TextAreaFieldProps) {
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>
                {label}
                {optional && <Optional />}
            </Label>
            <textarea
                id={id}
                rows={rows}
                maxLength={maxLength}
                value={value}
                className={cn(textareaClass, rows < 3 && 'min-h-0')}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy(hint && hintId, error && errorId)}
                onChange={(e) => onChange(e.target.value)}
            />
            {hint && (
                <p id={hintId} className="text-muted-foreground text-[13px]">
                    {hint}
                </p>
            )}
            <FieldError id={errorId} message={error} />
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
}

/** A native radio group in a fieldset with a legend. The first radio carries the id suffix "-0". */
export function RadioGroupField({ id, legend, options, value, onChange, error }: RadioGroupFieldProps) {
    const errorId = `${id}-error`;

    return (
        <fieldset id={id} className="grid gap-2" aria-describedby={error ? errorId : undefined}>
            <legend className="mb-1 text-sm font-medium">{legend}</legend>
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
}

export function CheckboxField({ id, label, checked, onChange, hint }: CheckboxFieldProps) {
    const hintId = `${id}-hint`;

    return (
        <div className="grid gap-1">
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
                <p id={hintId} className="text-muted-foreground text-[13px]">
                    {hint}
                </p>
            )}
        </div>
    );
}

/** A server-computed value shown read-only: a label and the value (or a dash). */
export function Figure({ label, value, suffix }: { label: string; value: ReactNode; suffix?: ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-muted-foreground text-[13px]">{label}</dt>
            <dd className="text-sm font-medium tabular-nums">
                {value === null || value === undefined || value === '' ? '—' : value}
                {suffix && value !== null && value !== undefined && value !== '' && (
                    <span className="text-muted-foreground font-normal"> {suffix}</span>
                )}
            </dd>
        </div>
    );
}
