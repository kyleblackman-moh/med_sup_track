import { cn } from '@/lib/utils';
import { type ElementType, type ReactNode, useId } from 'react';

interface PanelProps {
    /** The panel's heading (Title Case). Omit for a plain card. */
    title?: ReactNode;
    /** The heading level: h2 on a page, h3 inside a tab panel. */
    headingLevel?: 2 | 3;
    /** One 13px muted line under the title. */
    description?: ReactNode;
    /** On the right of the title: a count, a link, a small action. Never form actions. */
    actions?: ReactNode;
    /** The element (section by default; "div" for a plain card). */
    as?: ElementType;
    /** "none" for content that is flush to the card edges (a table, a list). */
    padding?: 'default' | 'none';
    id?: string;
    className?: string;
    bodyClassName?: string;
    children: ReactNode;
}

/**
 * OVERNIGHT-3 (B1): the content card (design.md section 5, L3): white,
 * 1px border, 12px radius, --shadow-card, padding 16px (20px from md; the
 * FORM-COMPACT-1 rhythm). The heading is 16px/600 (card title). Use the L2
 * SectionPanel for a grey panel holding a white card; do not nest two white
 * cards.
 */
export function Panel({
    title,
    headingLevel = 2,
    description,
    actions,
    as: Tag = 'section',
    padding = 'default',
    id,
    className,
    bodyClassName,
    children,
}: PanelProps) {
    const headingId = useId();
    const Heading = headingLevel === 3 ? 'h3' : 'h2';
    const padded = padding === 'default';
    const header = title !== undefined && (
        <div className={cn('flex flex-wrap items-start justify-between gap-3', padded ? 'mb-4' : 'border-b px-4 py-3 md:px-5')}>
            <div className="min-w-0 space-y-1">
                <Heading id={headingId} className="text-base font-semibold">
                    {title}
                </Heading>
                {description && <div className="text-muted-foreground text-helper">{description}</div>}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );

    return (
        <Tag
            id={id}
            aria-labelledby={title !== undefined && Tag === 'section' ? headingId : undefined}
            className={cn('bg-card shadow-card min-w-0 rounded-xl border', padded && 'p-4 md:p-5', !padded && 'overflow-hidden', className)}
        >
            {header}
            <div className={cn('min-w-0', bodyClassName)}>{children}</div>
        </Tag>
    );
}
