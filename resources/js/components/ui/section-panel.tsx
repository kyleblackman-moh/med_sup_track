import { cn } from '@/lib/utils';
import { type ReactNode, useId } from 'react';

interface SectionPanelProps {
    title: string;
    description?: ReactNode;
    /**
     * "split": the title in a left column (max 300px) and the white card on the
     * right, for a card that holds only fields. "stacked": the title above a
     * full-width card, for tables and entry rows (design.md section 7).
     */
    variant?: 'stacked' | 'split';
    /** The section's anchor (for a progress strip link). */
    id?: string;
    /** Extra content beside the title (for example a count). */
    aside?: ReactNode;
    /** V2-2b: the title's size and weight (default 14px/600; the vendor form uses 16px/600). */
    titleClassName?: string;
    /** A section number shown as a badge before the title (the vendor form; V2-4: 32px solid --primary circle). */
    number?: number | null;
    children: ReactNode;
    className?: string;
}

/**
 * UI-4: an L2 section panel (grey, 16px radius, 16px padding) holding an L3
 * white card (1px border, 12px radius, --shadow-card, 24px padding, 20px below md).
 * V2-4: the panel has a 1px --border, since the workspace and --muted are close.
 */
export function SectionPanel({ title, description, variant = 'stacked', id, aside, titleClassName, number, children, className }: SectionPanelProps) {
    const headingId = useId();
    const numbered = number !== undefined && number !== null;

    const heading = (
        <div className="min-w-0 space-y-1">
            <div className={cn('flex justify-between gap-2', numbered ? 'items-center' : 'items-baseline')}>
                <h2 id={headingId} className={cn('text-sm font-semibold', numbered && 'flex items-center gap-3', titleClassName)}>
                    {numbered && (
                        <>
                            <span
                                aria-hidden="true"
                                className="bg-primary text-primary-foreground flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-bold tabular-nums"
                            >
                                {number}
                            </span>
                            <span className="sr-only">{number}. </span>
                        </>
                    )}
                    {title}
                </h2>
                {aside}
            </div>
            {description && <div className="text-muted-foreground text-[13px]">{description}</div>}
        </div>
    );

    return (
        <section
            id={id}
            aria-labelledby={headingId}
            className={cn(
                'bg-muted scroll-mt-36 rounded-2xl border p-4',
                variant === 'split' ? 'grid gap-4 md:grid-cols-[minmax(0,300px)_minmax(0,1fr)]' : 'space-y-4',
                className,
            )}
        >
            {heading}
            <div className="bg-card shadow-card min-w-0 rounded-xl border p-5 md:p-6">{children}</div>
        </section>
    );
}
