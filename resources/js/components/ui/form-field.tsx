import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type ComponentProps, type ReactNode } from 'react';

/** The field label (15px/600; design.md section 4, MASTER.md section 2). */
export const fieldLabel = 'text-label leading-snug font-semibold';

/** A field label above its control. */
export function FieldLabel({ className, ...props }: ComponentProps<typeof Label>) {
    return <Label className={cn(fieldLabel, className)} {...props} />;
}

/** Helper text under a control: 13px muted, sentence case. Give it an id the control names in aria-describedby. */
export function FieldHint({ className, ...props }: ComponentProps<'p'>) {
    return <p className={cn('text-muted-foreground text-helper', className)} {...props} />;
}

interface FormFieldProps {
    /** The control's id; the label points at it, and `${id}-hint` / `${id}-error` are the described-by ids. */
    id: string;
    label: ReactNode;
    hint?: ReactNode;
    error?: string;
    className?: string;
    /** The control. Give it id={id}, aria-invalid and aria-describedby={describedBy(id, hint, error)}. */
    children: ReactNode;
}

/** The ids a control is described by: its hint and its error, when they show. */
export function describedBy(id: string, hint?: ReactNode, error?: string): string | undefined {
    return [hint ? `${id}-hint` : '', error ? `${id}-error` : ''].filter(Boolean).join(' ') || undefined;
}

/**
 * OVERNIGHT-3 (B1): one form field: the label (15px/600) above the control,
 * 8px between them, then the helper (13px) and the shared error (InputError:
 * icon and --destructive-text, linked by aria-describedby). The vendor
 * request form keeps its own field components (ad-hoc/fields.tsx), which use
 * the same label, helper and error styles.
 */
export function FormField({ id, label, hint, error, className, children }: FormFieldProps) {
    return (
        <div className={cn('grid min-w-0 content-start gap-2', className)}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            {children}
            {hint && <FieldHint id={`${id}-hint`}>{hint}</FieldHint>}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}
