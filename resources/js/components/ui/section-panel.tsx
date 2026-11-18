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
    children: ReactNode;
    className?: string;
}

/**
 * UI-4: an L2 section panel (grey, 16px radius, 16px padding) holding an L3
 * white card (1px border, 12px radius, --shadow-card, 24px padding, 20px below md).
 */
export function SectionPanel({ title, description, variant = 'stacked', id, aside, titleClassName, children, className }: SectionPanelProps) {
    const headingId = useId();

    const heading = (
        <div className="min-w-0 space-y-1">
            <div className="flex items-baseline justify-between gap-2">
                <h2 id={headingId} className={cn('text-sm font-semibold', titleClassName)}>
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
                'bg-muted scroll-mt-28 rounded-2xl p-4',
                variant === 'split' ? 'grid gap-4 md:grid-cols-[minmax(0,300px)_minmax(0,1fr)]' : 'space-y-4',
                className,
            )}
        >
            {heading}
            <div className="bg-card shadow-card min-w-0 rounded-xl border p-5 md:p-6">{children}</div>
        </section>
    );
}
