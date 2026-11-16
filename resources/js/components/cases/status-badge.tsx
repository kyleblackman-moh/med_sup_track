import { cn } from '@/lib/utils';
import { type StatusTone } from '@/types/cases';

/**
 * Tone → classes. The server decides each status's tone (CaseStatus::tone,
 * design.md section 3); React never reads status codes.
 */
const TONE_CLASSES: Record<StatusTone, string> = {
    neutral: 'bg-status-neutral text-status-neutral-foreground',
    info: 'bg-status-info text-status-info-foreground',
    success: 'bg-status-success text-status-success-foreground',
    warning: 'bg-status-warning text-status-warning-foreground',
    danger: 'bg-status-danger text-status-danger-foreground',
};

interface Props {
    label: string;
    tone: StatusTone;
    /** Shows a small dot; the label stays the main signal. */
    hasOpenQuery?: boolean;
    className?: string;
}

export function StatusBadge({ label, tone, hasOpenQuery = false, className }: Props) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                TONE_CLASSES[tone] ?? TONE_CLASSES.neutral,
                className,
            )}
        >
            {label}
            {hasOpenQuery && (
                <>
                    <span className="size-1.5 rounded-full bg-current" aria-hidden="true" />
                    <span className="sr-only">(open query)</span>
                </>
            )}
        </span>
    );
}
