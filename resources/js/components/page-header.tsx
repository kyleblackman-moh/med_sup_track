import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    /** One 13px muted line under the title (for example "Draft saved 15:08"). */
    subtitle?: ReactNode;
    /** On the right: a status pill or the page's primary list action. Never form actions. */
    actions?: ReactNode;
    className?: string;
}

/**
 * UI-4: the in-flow page header inside the L1 canvas (design.md section 6):
 * h1 20px/600, an optional subtitle, actions on the right. Not sticky. One h1
 * per page.
 */
export function PageHeader({ title, subtitle, actions, className }: PageHeaderProps) {
    return (
        <header className={cn('flex flex-wrap items-start justify-between gap-3', className)}>
            <div className="min-w-0 space-y-1">
                <h1 className="text-xl font-semibold tracking-[-0.01em]">{title}</h1>
                {subtitle && <div className="text-muted-foreground text-[13px]">{subtitle}</div>}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </header>
    );
}
