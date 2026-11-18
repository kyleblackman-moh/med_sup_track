import { cn } from '@/lib/utils';
import { type StatusTone } from '@/types/cases';

/**
 * Tone → classes (design.md section 3). The SERVER decides each tone (staff:
 * CaseStatus::tone, vendor: CaseStatus::vendorTone) and sends it with the
 * label; React never reads status codes.
 */
const TONE_CLASSES: Record<StatusTone, string> = {
    neutral: 'bg-status-neutral text-status-neutral-foreground',
    info: 'bg-status-info text-status-info-foreground',
    success: 'bg-status-success text-status-success-foreground',
    warning: 'bg-status-warning text-status-warning-foreground',
    danger: 'bg-status-danger text-status-danger-foreground',
};

interface StatusPillProps {
    label: string;
    tone: StatusTone;
    /** Staff lists: a second marker for an open query; the label stays the main signal. */
    openQuery?: boolean;
    /** V2-2c: in a table column, every pill shares a 96px min-width so the column lines up. Never in a page header. */
    inColumn?: boolean;
    className?: string;
}

/**
 * UI-4: the one status pill (design.md sections 3 and 7): 24px high, full
 * radius, 12px/500, a 6px dot in the text colour, always a text label. In a
 * table column (inColumn) a shared min-width (96px) keeps the pills lined up.
 */
export function StatusPill({ label, tone, openQuery = false, inColumn = false, className }: StatusPillProps) {
    return (
        <span
            className={cn(
                'inline-flex h-6 items-center gap-1.5 rounded-full px-2.5 text-xs font-medium whitespace-nowrap',
                inColumn && 'min-w-24',
                TONE_CLASSES[tone] ?? TONE_CLASSES.neutral,
                className,
            )}
        >
            <span className="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true" />
            {label}
            {openQuery && (
                <>
                    <span className="ml-auto size-1.5 shrink-0 rounded-full ring-1 ring-current" aria-hidden="true" />
                    <span className="sr-only">(open query)</span>
                </>
            )}
        </span>
    );
}
