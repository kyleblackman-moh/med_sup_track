import { cn } from '@/lib/utils';
import { type StatusTone } from '@/types/cases';

/**
 * Tone → daisyUI badge classes (design.md section 3). The SERVER decides each tone (staff:
 * CaseStatus::tone, vendor: CaseStatus::vendorTone) and sends it with the
 * label; React never reads status codes.
 */
const TONE_CLASSES: Record<StatusTone, string> = {
    neutral: 'badge-soft badge-neutral',
    info: 'badge-soft badge-info',
    success: 'badge-soft badge-success',
    warning: 'badge-soft badge-warning',
    danger: 'badge-soft badge-error',
};

interface StatusPillProps {
    label: string;
    tone: StatusTone;
    /** Staff lists: a second marker for an open query; the label stays the main signal. */
    openQuery?: boolean;
    className?: string;
}

/**
 * UI-4: the one status pill (design.md sections 3 and 7): 24px high, full
 * radius, 12px/500, a 6px dot in the text colour, always a text label. A
 * shared min-width (96px) keeps pills in a column lined up.
 */
export function StatusPill({ label, tone, openQuery = false, className }: StatusPillProps) {
    return (
        <span
            className={cn(
                'badge h-6 min-w-24 justify-start gap-1.5 px-2.5 text-xs font-medium whitespace-nowrap',
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
