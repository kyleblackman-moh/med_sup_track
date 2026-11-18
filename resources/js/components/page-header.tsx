import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    /** One 13px muted line under the title (for example "Draft saved 15:08"). */
    subtitle?: ReactNode;
    /** On the right: a status pill or the page's primary list action. Never form actions. */
    actions?: ReactNode;
    /** V2-3: extra classes for the h1 (for example font-mono when the title is a reference). */
    titleClassName?: string;
    /** NEXT: "large" (vendor form and vendor Requests page): h1 22px, subtitle 14px. */
    size?: 'default' | 'large';
    className?: string;
}

/**
 * UI-4: the in-flow page header inside the L1 canvas (design.md section 6):
 * h1 20px/600, an optional subtitle, actions on the right. Not sticky. One h1
 * per page.
 */
export function PageHeader({ title, subtitle, actions, titleClassName, size = 'default', className }: PageHeaderProps) {
    const large = size === 'large';

    return (
        <header className={cn('flex flex-wrap items-start justify-between gap-3', className)}>
            <div className="min-w-0 space-y-1">
                <h1 className={cn('font-semibold tracking-[-0.01em]', large ? 'text-[22px] leading-tight' : 'text-xl', titleClassName)}>{title}</h1>
                {subtitle && <div className={cn('text-muted-foreground', large ? 'text-sm' : 'text-[13px]')}>{subtitle}</div>}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </header>
    );
}

/**
 * V2-2c: the page's content area on the L1 canvas: 20px at the top and 24px at
 * the sides (16px below md), and a uniform 20px between the page header and the
 * blocks after it (a progress strip, the first section). Used by the pages that
 * have a PageHeader.
 */
export function PageContent({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('w-full min-w-0 space-y-5 px-4 pt-5 pb-6 md:px-6', className)}>{children}</div>;
}
