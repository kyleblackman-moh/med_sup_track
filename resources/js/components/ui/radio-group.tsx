import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * RADIO-1: the one radio pattern for the whole app (Blueprint: fieldset +
 * radio). A fieldset with a legend, then the options as a vertical stack;
 * a caller may lay a short list out side by side (stackClassName, as the
 * request type cards and RFQ Sent To do). Each option is a full-width clickable row:
 * a native `radio radio-sm`, its label and an optional description. The
 * checked row gets a subtle primary border and wash; the radio's own dot
 * shows the choice too (never colour alone). Native radios, so the arrow keys
 * move between the options of one name.
 */

export interface RadioChoice {
    value: string;
    label: ReactNode;
    /** A second, muted line under the label. */
    description?: ReactNode;
    disabled?: boolean;
}

/** The vertical stack of option rows. */
export function RadioStack({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('flex min-w-0 flex-col gap-2', className)}>{children}</div>;
}

interface RadioOptionProps {
    id: string;
    name: string;
    value: string;
    checked: boolean;
    onChange: (value: string) => void;
    label: ReactNode;
    description?: ReactNode;
    /** A leading visual (the request type's icon square), hidden from assistive technology by the caller. */
    icon?: ReactNode;
    disabled?: boolean;
    required?: boolean;
}

/** One option: a full-width row that selects its radio anywhere it is clicked. */
export function RadioOption({ id, name, value, checked, onChange, label, description, icon, disabled, required }: RadioOptionProps) {
    return (
        <label
            htmlFor={id}
            className="border-base-300 rounded-field text-base-content hover:border-base-content/30 has-[:checked]:border-primary has-[:checked]:bg-primary/5 flex min-h-11 w-full min-w-0 cursor-pointer items-start gap-3 border px-3 py-2.5 text-sm transition-colors duration-150 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60 md:min-h-10 motion-reduce:transition-none"
        >
            <input
                type="radio"
                id={id}
                name={name}
                value={value}
                checked={checked}
                disabled={disabled}
                required={required}
                onChange={() => onChange(value)}
                className="radio radio-sm mt-px shrink-0"
            />
            {icon}
            <span className="grid min-w-0 gap-0.5">
                <span className="font-medium break-words">{label}</span>
                {description && <span className="text-base-content/70 break-words">{description}</span>}
            </span>
        </label>
    );
}

interface RadioGroupProps {
    /** The radios' shared name. */
    name: string;
    legend: ReactNode;
    options: RadioChoice[];
    /** The chosen value; '' or null when nothing is chosen (nothing is preselected). */
    value: string | null;
    onChange: (value: string) => void;
    /** The id of each radio (an error summary may link to it). Default: `${name}-${index}`. */
    optionId?: (option: RadioChoice, index: number) => string;
    /** The fieldset id (a jump-link target). */
    id?: string;
    legendId?: string;
    /** Labels are capitalised; a question legend stays sentence case. */
    legendCase?: 'title' | 'sentence';
    describedBy?: string;
    required?: boolean;
    disabled?: boolean;
    /** -1 when an error summary focuses the fieldset itself. */
    tabIndex?: number;
    className?: string;
    /** Shown between the legend and the options (a hint). */
    intro?: ReactNode;
    /** Shown after the options (the error, a note). */
    children?: ReactNode;
    /** Classes for the options' stack (for example two columns for a short list). */
    stackClassName?: string;
}

/** A labelled group of radios: fieldset, legend, the vertical option stack, then the caller's error and notes. */
export function RadioGroup({
    name,
    legend,
    options,
    value,
    onChange,
    optionId = (_option, index) => `${name}-${index}`,
    id,
    legendId,
    legendCase = 'title',
    describedBy,
    required,
    disabled,
    tabIndex,
    className,
    intro,
    children,
    stackClassName,
}: RadioGroupProps) {
    return (
        <fieldset
            id={id}
            className={cn('fieldset min-w-0 gap-2 py-0 outline-none', className)}
            aria-describedby={describedBy}
            disabled={disabled}
            tabIndex={tabIndex}
        >
            <legend id={legendId} className={legendCase === 'sentence' ? 'fieldset-legend pt-0 text-sm' : 'fieldset-legend pt-0 text-sm capitalize'}>
                {legend}
            </legend>
            {intro}
            <RadioStack className={stackClassName}>
                {options.map((option, index) => (
                    <RadioOption
                        key={option.value}
                        id={optionId(option, index)}
                        name={name}
                        value={option.value}
                        checked={value === option.value}
                        onChange={onChange}
                        label={option.label}
                        description={option.description}
                        disabled={option.disabled}
                        required={required}
                    />
                ))}
            </RadioStack>
            {children}
        </fieldset>
    );
}
