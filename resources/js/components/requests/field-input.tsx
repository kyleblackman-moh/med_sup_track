import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';
import { type FormConfig } from '@/types/requests';
import { type FieldDef } from './fields';

interface FieldInputProps {
    id: string;
    field: FieldDef;
    value: string | boolean;
    error?: string;
    config: FormConfig;
    onChange: (value: string | boolean) => void;
    compact?: boolean;
}

/**
 * One labelled daisyUI control (input, select, textarea or checkbox). Numbers
 * are text inputs holding strings (no float conversion in the browser).
 */
export function FieldInput({ id, field, value, error, config, onChange, compact = false }: FieldInputProps) {
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;
    const labelId = `${id}-label`;
    const describedBy = [error ? errorId : null, field.hint ? hintId : null].filter(Boolean).join(' ') || undefined;
    const common = { 'aria-invalid': error ? true : undefined, 'aria-describedby': describedBy };
    const labelClass = cn('label text-base-content text-sm font-medium whitespace-normal capitalize', compact && 'sr-only');

    if (field.kind === 'boolean') {
        return (
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <input
                        id={id}
                        aria-labelledby={labelId}
                        {...common}
                        type="checkbox"
                        className={cn('checkbox checkbox-sm', error && 'checkbox-error')}
                        checked={value === true}
                        onChange={(e) => onChange(e.target.checked)}
                    />
                    <label id={labelId} htmlFor={id} className={cn(labelClass, 'cursor-pointer')}>
                        {field.label}
                    </label>
                </div>
                <InputError id={errorId} message={error} />
            </div>
        );
    }

    const text = typeof value === 'string' ? value : '';
    let control;

    if (field.kind === 'textarea') {
        control = (
            <textarea
                id={id}
                aria-labelledby={labelId}
                {...common}
                rows={3}
                className={cn('textarea textarea-sm min-h-16 w-full', error && 'textarea-error')}
                value={text}
                onChange={(e) => onChange(e.target.value)}
            />
        );
    } else if (field.kind === 'select' && field.options) {
        control = (
            <select
                id={id}
                aria-labelledby={labelId}
                {...common}
                className={cn('select select-sm w-full', error && 'select-error')}
                value={text}
                onChange={(e) => onChange(e.target.value)}
            >
                {field.name !== 'previous_price_maintained' && <option value="">Choose…</option>}
                {config.options[field.options].map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        );
    } else {
        control = (
            <input
                id={id}
                aria-labelledby={labelId}
                {...common}
                type={field.kind === 'date' ? 'date' : 'text'}
                inputMode={field.kind === 'decimal' ? 'decimal' : field.kind === 'integer' ? 'numeric' : undefined}
                className={cn(
                    'input input-sm w-full',
                    error && 'input-error',
                    (field.kind === 'decimal' || field.kind === 'integer') && 'tabular-nums',
                )}
                value={text}
                onChange={(e) => onChange(e.target.value)}
            />
        );
    }

    return (
        <div className="grid min-w-0 content-start gap-1.5">
            <label id={labelId} htmlFor={id} className={labelClass}>
                {field.label}
            </label>
            {control}
            {field.hint && (
                <p id={hintId} className="text-base-content/70 text-xs">
                    {field.hint}
                </p>
            )}
            <InputError id={errorId} message={error} />
        </div>
    );
}
