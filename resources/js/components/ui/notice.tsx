import { cn } from '@/lib/utils';
import { CircleAlert, CircleCheck, Info, TriangleAlert, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

export type NoticeTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

const TONES: Record<NoticeTone, { classes: string; icon: LucideIcon }> = {
    neutral: { classes: 'bg-muted text-foreground border-border', icon: Info },
    info: { classes: 'bg-status-info text-status-info-foreground border-status-info-foreground/30', icon: Info },
    success: { classes: 'bg-status-success text-status-success-foreground border-status-success-foreground/30', icon: CircleCheck },
    warning: { classes: 'bg-status-warning text-status-warning-foreground border-status-warning-foreground/30', icon: TriangleAlert },
    danger: { classes: 'bg-status-danger text-status-danger-foreground border-status-danger-foreground/30', icon: CircleAlert },
};

interface NoticeProps {
    tone?: NoticeTone;
    /** A short Title Case heading, when the notice needs one. */
    title?: ReactNode;
    children?: ReactNode;
    /** "alert" for an error after an action, "status" for a polite confirmation; none for a static notice. */
    role?: 'alert' | 'status';
    /** Hide the icon (the text still carries the meaning). */
    plain?: boolean;
    id?: string;
    className?: string;
}

/**
 * OVERNIGHT-3 (B1): a notice or alert on the status tone pairs (design.md
 * section 3; 5.24 to 6.80:1). An icon AND text, never colour alone. Error
 * text inside a form stays the field error component (InputError).
 */
export function Notice({ tone = 'neutral', title, children, role, plain = false, id, className }: NoticeProps) {
    const { classes, icon: Icon } = TONES[tone];

    return (
        <div id={id} role={role} className={cn('flex gap-3 rounded-lg border px-4 py-3 text-sm', classes, className)}>
            {!plain && <Icon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />}
            <div className="min-w-0 space-y-1 break-words">
                {title && <p className="font-semibold">{title}</p>}
                {children}
            </div>
        </div>
    );
}
