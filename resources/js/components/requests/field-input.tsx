import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type FormConfig } from '@/types/requests';
import { type FieldDef } from './fields';

const controlClass =
    'border-input bg-transparent placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] md:text-sm';

interface FieldInputProps {
    id: string;
    field: FieldDef;
    value: string | boolean;
    error?: string;
    config: FormConfig;
    onChange: (value: string | boolean) => void;
    compact?: boolean;
}

/** One labelled input. Numbers are text inputs holding strings (no float conversion in the browser). */
export function FieldInput({ id, field, value, error, config, onChange, compact = false }: FieldInputProps) {
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;
    const describedBy = [error ? errorId : null, field.hint ? hintId : null].filter(Boolean).join(' ') || undefined;
    const common = { id, 'aria-invalid': error ? true : undefined, 'aria-describedby': describedBy };

    if (field.kind === 'boolean') {
        return (
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <input {...common} type="checkbox" className="size-4" checked={value === true} onChange={(e) => onChange(e.target.checked)} />
                    <Label htmlFor={id} className={cn(compact && 'sr-only')}>
                        {field.label}
                    </Label>
                </div>
                <InputError id={errorId} message={error} />
            </div>
        );
    }

    const text = typeof value === 'string' ? value : '';
    let control;

    if (field.kind === 'textarea') {
        control = (
            <textarea {...common} rows={3} className={cn(controlClass, 'min-h-16 py-2')} value={text} onChange={(e) => onChange(e.target.value)} />
        );
    } else if (field.kind === 'select' && field.options) {
        control = (
            <select {...common} className={cn(controlClass, 'h-9')} value={text} onChange={(e) => onChange(e.target.value)}>
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
            <Input
                {...common}
                type={field.kind === 'date' ? 'date' : 'text'}
                inputMode={field.kind === 'decimal' ? 'decimal' : field.kind === 'integer' ? 'numeric' : undefined}
                value={text}
                onChange={(e) => onChange(e.target.value)}
            />
        );
    }

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id} className={cn(compact && 'sr-only')}>
                {field.label}
            </Label>
            {control}
            {field.hint && (
                <p id={hintId} className="text-muted-foreground text-xs">
                    {field.hint}
                </p>
            )}
            <InputError id={errorId} message={error} />
        </div>
    );
}
