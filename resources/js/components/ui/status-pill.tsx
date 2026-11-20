import { cn } from '@/lib/utils';
import { type StatusTone } from '@/types/cases';

/**
 * Tone → full static badge classes. The SERVER decides each tone (staff:
 * CaseStatus::tone, vendor: CaseStatus::vendorTone) and sends it with the
 * label; React never reads status codes. The colours live in the status
 * palette in resources/css/app.css ("moh" theme): change one line there and
 * every pill, dot and legend using that tone follows. The dot is bg-current.
 */
export const TONE_CLASSES: Record<StatusTone, string> = {
    neutral: 'badge-soft badge-neutral',
    info: 'badge-soft badge-info',
    success: 'badge-soft badge-success',
    warning: 'badge-soft badge-warning',
    danger: 'badge-soft badge-error',
    indigo: 'bg-status-indigo/10 text-status-indigo border-status-indigo/20',
    violet: 'bg-status-violet/10 text-status-violet border-status-violet/20',
    teal: 'bg-status-teal/10 text-status-teal border-status-teal/20',
    cyan: 'bg-status-cyan/10 text-status-cyan border-status-cyan/20',
    purple: 'bg-status-purple/10 text-status-purple border-status-purple/20',
    fuchsia: 'bg-status-fuchsia/10 text-status-fuchsia border-status-fuchsia/20',
    lime: 'bg-status-lime/10 text-status-lime border-status-lime/20',
    slate: 'bg-status-slate/10 text-status-slate border-status-slate/20',
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
 * UI-4: the one status pill (design.md section 7; colours: the status palette in app.css): 24px high, full
 * radius, 12px/500, a 6px dot in the text colour, always a text label. A
 * shared min-width (96px) keeps pills in a column lined up.
 */
export function StatusPill({ label, tone, openQuery = false, inColumn = false, className }: StatusPillProps) {
    return (
        <span
            className={cn(
                'badge h-6 justify-start gap-1.5 px-2.5 text-xs font-medium whitespace-nowrap',
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
